import base64
import cv2
import numpy as np
import json
from PIL import Image
import io
import os
import sys

class ImageEncoder:
    def __init__(self):
        self.supported_formats = ['jpg', 'jpeg', 'png', 'bmp']
        
        # Initialize multiple face detectors for better detection
        self.face_cascade_default = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        self.face_cascade_alt = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_alt.xml')
        self.face_cascade_profile = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_profileface.xml')
        
        # Try to initialize DNN face detector if available
        self.dnn_net = None
        try:
            # You can download these files and place them in your project directory
            # For now, we'll use cascade classifiers only
            pass
        except:
            pass
    
    def validate_image(self, image_path):
        """Validate if the uploaded image is valid and contains a face"""
        try:
            # Check if file exists
            if not os.path.exists(image_path):
                return False, "Image file not found"
            
            # Check file size
            file_size = os.path.getsize(image_path)
            if file_size == 0:
                return False, "Image file is empty"
            
            # Check file extension
            file_ext = image_path.lower().split('.')[-1]
            if file_ext not in self.supported_formats:
                return False, f"Unsupported format. Supported formats: {', '.join(self.supported_formats)}"
            
            # Load and validate image
            image = cv2.imread(image_path)
            if image is None:
                return False, "Could not load image file"
            
            # Check image dimensions
            height, width = image.shape[:2]
            if height < 50 or width < 50:
                return False, "Image is too small for face detection"
            
            # Convert to grayscale for face detection
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            
            # Try multiple detection methods
            faces = self._detect_faces_multiple_methods(gray)
            
            if len(faces) == 0:
                return False, "No face detected in the image. Please ensure good lighting and face is clearly visible."
            
            if len(faces) > 5:  # Relaxed from 1 to allow some flexibility
                return False, f"Too many faces detected ({len(faces)}). Please upload an image with fewer faces"
            
            return True, f"Image is valid - {len(faces)} face(s) detected"
            
        except Exception as e:
            return False, f"Error validating image: {str(e)}"
    
    def _detect_faces_multiple_methods(self, gray_image):
        """Try multiple face detection methods for better accuracy"""
        all_faces = []
        
        # Method 1: Default frontal face detector with multiple scale factors
        for scale_factor in [1.05, 1.1, 1.15, 1.2, 1.3]:
            for min_neighbors in [3, 4, 5, 6]:
                try:
                    faces = self.face_cascade_default.detectMultiScale(
                        gray_image,
                        scaleFactor=scale_factor,
                        minNeighbors=min_neighbors,
                        minSize=(20, 20),  # Reduced minimum size
                        maxSize=(300, 300),  # Added maximum size
                        flags=cv2.CASCADE_SCALE_IMAGE
                    )
                    if len(faces) > 0:
                        all_faces.extend(faces)
                except:
                    continue
        
        # Method 2: Alternative frontal face detector
        try:
            faces_alt = self.face_cascade_alt.detectMultiScale(
                gray_image,
                scaleFactor=1.1,
                minNeighbors=4,
                minSize=(20, 20),
                maxSize=(300, 300)
            )
            if len(faces_alt) > 0:
                all_faces.extend(faces_alt)
        except:
            pass
        
        # Method 3: Profile face detector
        try:
            faces_profile = self.face_cascade_profile.detectMultiScale(
                gray_image,
                scaleFactor=1.1,
                minNeighbors=4,
                minSize=(20, 20),
                maxSize=(300, 300)
            )
            if len(faces_profile) > 0:
                all_faces.extend(faces_profile)
        except:
            pass
        
        # Method 4: Try with histogram equalization
        try:
            equalized = cv2.equalizeHist(gray_image)
            faces_eq = self.face_cascade_default.detectMultiScale(
                equalized,
                scaleFactor=1.1,
                minNeighbors=4,
                minSize=(20, 20),
                maxSize=(300, 300)
            )
            if len(faces_eq) > 0:
                all_faces.extend(faces_eq)
        except:
            pass
        
        # Method 5: Try with CLAHE (Contrast Limited Adaptive Histogram Equalization)
        try:
            clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
            clahe_img = clahe.apply(gray_image)
            faces_clahe = self.face_cascade_default.detectMultiScale(
                clahe_img,
                scaleFactor=1.1,
                minNeighbors=4,
                minSize=(20, 20),
                maxSize=(300, 300)
            )
            if len(faces_clahe) > 0:
                all_faces.extend(faces_clahe)
        except:
            pass
        
        # Remove duplicates and merge overlapping detections
        if len(all_faces) > 0:
            all_faces = self._merge_overlapping_faces(all_faces)
        
        return all_faces
    
    def _merge_overlapping_faces(self, faces):
        """Merge overlapping face detections"""
        if len(faces) <= 1:
            return faces
        
        # Convert to list of tuples for easier processing
        faces_list = [tuple(face) for face in faces]
        merged_faces = []
        
        for face in faces_list:
            x1, y1, w1, h1 = face
            is_duplicate = False
            
            for existing_face in merged_faces:
                x2, y2, w2, h2 = existing_face
                
                # Calculate overlap
                overlap_x = max(0, min(x1 + w1, x2 + w2) - max(x1, x2))
                overlap_y = max(0, min(y1 + h1, y2 + h2) - max(y1, y2))
                overlap_area = overlap_x * overlap_y
                
                area1 = w1 * h1
                area2 = w2 * h2
                
                # If overlap is significant, consider it a duplicate
                if overlap_area > 0.3 * min(area1, area2):
                    is_duplicate = True
                    break
            
            if not is_duplicate:
                merged_faces.append(face)
        
        return np.array(merged_faces)
    
    def extract_face_features(self, image_path):
        """Extract face features using multiple methods for better accuracy"""
        try:
            # Load image
            image = cv2.imread(image_path)
            if image is None:
                return None, "Could not load image file"
            
            # Convert to grayscale
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            
            # Detect faces using improved method
            faces = self._detect_faces_multiple_methods(gray)
            
            if len(faces) == 0:
                return None, "No face found in image"
            
            # Get the largest face (most likely to be the main subject)
            if len(faces) > 1:
                face_areas = [w * h for (x, y, w, h) in faces]
                largest_face_idx = np.argmax(face_areas)
                x, y, w, h = faces[largest_face_idx]
            else:
                x, y, w, h = faces[0]
            
            # Add some padding around the face
            padding = int(min(w, h) * 0.1)
            x = max(0, x - padding)
            y = max(0, y - padding)
            w = min(gray.shape[1] - x, w + 2 * padding)
            h = min(gray.shape[0] - y, h + 2 * padding)
            
            # Extract face region
            face_roi = gray[y:y+h, x:x+w]
            
            # Resize face to standard size for consistency
            face_resized = cv2.resize(face_roi, (100, 100))
            
            # Apply preprocessing to improve feature extraction
            face_processed = self._preprocess_face(face_resized)
            
            # Extract multiple types of features for better recognition
            features = []
            
            # 1. Histogram features
            hist_features = self._extract_histogram_features(face_processed)
            features.extend(hist_features)
            
            # 2. Simple LBP features (fixed version)
            lbp_features = self._extract_simple_lbp_features(face_processed)
            features.extend(lbp_features)
            
            # 3. Pixel intensity features (normalized)
            pixel_features = self._extract_pixel_features(face_processed)
            features.extend(pixel_features)
            
            # 4. Gradient features
            gradient_features = self._extract_gradient_features(face_processed)
            features.extend(gradient_features)
            
            # Convert to numpy array and normalize
            features = np.array(features, dtype=np.float32)
            features = self._normalize_features(features)
            
            # Store face data
            face_data = {
                'features': features.tolist(),
                'face_region': {
                    'x': int(x),
                    'y': int(y),
                    'width': int(w),
                    'height': int(h)
                },
                'face_image_base64': self._face_to_base64(face_resized),
                'total_faces_detected': len(faces)
            }
            
            return face_data, "Face feature extraction successful"
            
        except Exception as e:
            return None, f"Error extracting face features: {str(e)}"
    
    def _preprocess_face(self, face_image):
        """Preprocess face image for better feature extraction"""
        try:
            # Apply CLAHE for better contrast
            clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8,8))
            enhanced = clahe.apply(face_image)
            
            # Apply Gaussian blur to reduce noise
            blurred = cv2.GaussianBlur(enhanced, (3, 3), 0)
            
            return blurred
        except:
            return face_image
    
    def _extract_gradient_features(self, face_image):
        """Extract gradient-based features"""
        try:
            # Calculate gradients
            grad_x = cv2.Sobel(face_image, cv2.CV_64F, 1, 0, ksize=3)
            grad_y = cv2.Sobel(face_image, cv2.CV_64F, 0, 1, ksize=3)
            
            # Gradient magnitude and direction
            magnitude = np.sqrt(grad_x**2 + grad_y**2)
            direction = np.arctan2(grad_y, grad_x)
            
            # Extract statistical features from gradients
            features = [
                np.mean(magnitude),
                np.std(magnitude),
                np.mean(direction),
                np.std(direction),
                np.max(magnitude),
                np.min(magnitude)
            ]
            
            return features
        except:
            return [0.0] * 6
    
    def _extract_histogram_features(self, face_image):
        """Extract histogram features from face image"""
        try:
            # Calculate histogram
            hist = cv2.calcHist([face_image], [0], None, [64], [0, 256])  # Reduced bins for efficiency
            # Normalize histogram
            hist = hist.flatten()
            hist = hist / (hist.sum() + 1e-7)  # Avoid division by zero
            return hist.tolist()
        except:
            return [0.0] * 64
    
    def _extract_simple_lbp_features(self, face_image):
        """Extract simplified LBP features - IMPROVED VERSION"""
        try:
            lbp_features = []
            
            # Divide face into regions for local features
            h, w = face_image.shape
            regions_h, regions_w = 6, 6  # Increased to 6x6 grid for more detail
            region_h, region_w = h // regions_h, w // regions_w
            
            for i in range(regions_h):
                for j in range(regions_w):
                    # Extract region
                    y_start, y_end = i * region_h, min((i + 1) * region_h, h)
                    x_start, x_end = j * region_w, min((j + 1) * region_w, w)
                    region = face_image[y_start:y_end, x_start:x_end]
                    
                    if region.size == 0:
                        lbp_features.extend([0.0, 0.0, 0.0, 0.0])
                        continue
                    
                    # Statistical features for this region
                    mean_val = np.mean(region)
                    std_val = np.std(region)
                    
                    # Add some simple pattern features
                    try:
                        grad_x = cv2.Sobel(region, cv2.CV_64F, 1, 0, ksize=3)
                        grad_y = cv2.Sobel(region, cv2.CV_64F, 0, 1, ksize=3)
                        grad_magnitude = np.sqrt(grad_x**2 + grad_y**2)
                        
                        lbp_features.extend([
                            float(mean_val),
                            float(std_val),
                            float(np.mean(grad_magnitude)),
                            float(np.std(grad_magnitude))
                        ])
                    except:
                        lbp_features.extend([
                            float(mean_val),
                            float(std_val),
                            0.0,
                            0.0
                        ])
            
            return lbp_features
            
        except Exception as e:
            # Fallback to simple statistical features
            try:
                return [
                    float(np.mean(face_image)),
                    float(np.std(face_image)),
                    float(np.min(face_image)),
                    float(np.max(face_image))
                ]
            except:
                return [0.0, 0.0, 0.0, 0.0]
    
    def _extract_pixel_features(self, face_image):
        """Extract normalized pixel intensity features"""
        try:
            # Resize to smaller size for manageable feature vector
            small_face = cv2.resize(face_image, (16, 16))  # Reduced from 20x20 for efficiency
            # Normalize pixel values
            pixels = small_face.flatten().astype(np.float32) / 255.0
            return pixels.tolist()
        except:
            return [0.0] * 256
    
    def _normalize_features(self, features):
        """Normalize feature vector"""
        try:
            # Handle edge cases
            if len(features) == 0:
                return features
            
            # Convert to float32
            features = features.astype(np.float32)
            
            # Replace any NaN or inf values
            features = np.nan_to_num(features, nan=0.0, posinf=1.0, neginf=-1.0)
            
            # L2 normalization
            norm = np.linalg.norm(features)
            if norm > 1e-6:  # More robust threshold
                features = features / norm
            
            return features
        except:
            return np.zeros_like(features, dtype=np.float32)
    
    def _face_to_base64(self, face_image):
        """Convert face image to base64 string for storage"""
        try:
            # Encode image to PNG format
            _, buffer = cv2.imencode('.png', face_image)
            # Convert to base64
            face_base64 = base64.b64encode(buffer).decode('utf-8')
            return face_base64
        except:
            return None
    
    def process_student_image(self, image_path, student_id):
        """Complete processing of student image for registration"""
        try:
            # Log processing start
            # sys.stderr.write(f"Starting image processing for student {student_id}\n")
            
            # Validate image
            is_valid, validation_msg = self.validate_image(image_path)
            # sys.stderr.write(f"Validation result: {is_valid}, {validation_msg}\n")
            
            if not is_valid:
                return {
                    'success': False,
                    'message': validation_msg,
                    'data': None
                }
            
            # Extract face features
            face_data, extraction_msg = self.extract_face_features(image_path)
            # sys.stderr.write(f"Feature extraction result: {extraction_msg}\n")
            
            if face_data is None:
                return {
                    'success': False,
                    'message': extraction_msg,
                    'data': None
                }
            
            # Format response to match PHP expectations
            processed_data = {
                'face_encoding': face_data['features'],  # Direct array for PHP compareFaces()
                'student_id': student_id,
                'face_features_json': json.dumps(face_data['features']),  # For database storage
                'face_region': json.dumps(face_data['face_region']),
                'face_image_base64': face_data['face_image_base64'],
                'features_length': len(face_data['features']),
                'validation_message': validation_msg,
                'extraction_method': 'OpenCV_Enhanced_Features',
                'total_faces_detected': face_data.get('total_faces_detected', 1)
            }
            
            # sys.stderr.write(f"Successfully processed {len(face_data['features'])} features\n")
            
            return {
                'success': True,
                'message': 'Face encoding successful',
                'data': processed_data
            }
            
        except Exception as e:
            error_msg = f"Error processing image: {str(e)}"
            # sys.stderr.write(f"Processing error: {error_msg}\n")
            return {
                'success': False,
                'message': error_msg,
                'data': None
            }
    
    def compare_faces(self, features1, features2, threshold=0.6):  # Lowered threshold slightly
        """Compare two face feature sets using multiple similarity metrics"""
        try:
            # Convert to numpy arrays if they're lists
            if isinstance(features1, list):
                features1 = np.array(features1, dtype=np.float32)
            if isinstance(features2, list):
                features2 = np.array(features2, dtype=np.float32)
            
            # Handle NaN values
            features1 = np.nan_to_num(features1, nan=0.0)
            features2 = np.nan_to_num(features2, nan=0.0)
            
            # Ensure same length
            if len(features1) != len(features2):
                return {
                    'is_match': False,
                    'similarity_score': 0.0,
                    'error': 'Feature vectors have different lengths'
                }
            
            # Calculate multiple similarity metrics
            
            # 1. Cosine similarity
            dot_product = np.dot(features1, features2)
            norm1 = np.linalg.norm(features1)
            norm2 = np.linalg.norm(features2)
            
            if norm1 > 1e-6 and norm2 > 1e-6:
                cosine_sim = dot_product / (norm1 * norm2)
            else:
                cosine_sim = 0
            
            # 2. Euclidean distance (converted to similarity)
            euclidean_dist = np.linalg.norm(features1 - features2)
            euclidean_sim = 1 / (1 + euclidean_dist)
            
            # 3. Correlation coefficient
            try:
                correlation = np.corrcoef(features1, features2)[0, 1]
                if np.isnan(correlation):
                    correlation = 0
            except:
                correlation = 0
            
            # 4. Manhattan distance similarity
            manhattan_dist = np.sum(np.abs(features1 - features2))
            manhattan_sim = 1 / (1 + manhattan_dist)
            
            # Combined similarity score (weighted average)
            similarity = (cosine_sim * 0.35 + euclidean_sim * 0.25 + 
                         (correlation + 1) / 2 * 0.25 + manhattan_sim * 0.15)
            
            # Ensure similarity is between 0 and 1
            similarity = max(0, min(1, similarity))
            
            is_match = similarity >= threshold
            
            return {
                'is_match': is_match,
                'similarity_score': float(similarity),
                'cosine_similarity': float(cosine_sim),
                'euclidean_similarity': float(euclidean_sim),
                'correlation': float(correlation),
                'manhattan_similarity': float(manhattan_sim),
                'euclidean_distance': float(euclidean_dist),
                'manhattan_distance': float(manhattan_dist),
                'threshold': threshold
            }
            
        except Exception as e:
            return {
                'is_match': False,
                'similarity_score': 0.0,
                'error': str(e)
            }

def register_student_image(image_path, student_id):
    """Function to be called from PHP during student registration"""
    try:
        encoder = ImageEncoder()
        result = encoder.process_student_image(image_path, student_id)
        
        # Return JSON response
        return json.dumps(result)
    except Exception as e:
        error_msg = f'Critical error: {str(e)}'
        # sys.stderr.write(f"Critical error in register_student_image: {error_msg}\n")
        return json.dumps({
            'success': False,
            'message': error_msg,
            'data': None
        })

def compare_student_faces(features1_json, features2_json, threshold=0.6):
    """Function to compare two sets of face features"""
    try:
        encoder = ImageEncoder()
        
        # Parse JSON strings back to lists
        features1 = json.loads(features1_json) if isinstance(features1_json, str) else features1_json
        features2 = json.loads(features2_json) if isinstance(features2_json, str) else features2_json
        
        result = encoder.compare_faces(features1, features2, threshold)
        
        return json.dumps(result)
    except Exception as e:
        return json.dumps({
            'is_match': False,
            'error': f'Comparison error: {str(e)}'
        })

if __name__ == "__main__":
    # Check if being called directly or as a module
    if len(sys.argv) < 2:
        # If no arguments, try to read from stdin (for PHP integration)
        try:
            input_data = input().strip()
            if input_data:
                # Parse JSON input if provided
                try:
                    data = json.loads(input_data)
                    image_path = data.get('image_path')
                    student_id = data.get('student_id')
                except:
                    # Assume space-separated arguments
                    parts = input_data.split(' ', 1)
                    image_path = parts[0] if len(parts) > 0 else None
                    student_id = parts[1] if len(parts) > 1 else None
            else:
                image_path = None
                student_id = None
        except:
            image_path = None
            student_id = None
    else:
        # Command line arguments provided
        if len(sys.argv) < 3:
            error_response = {
                'success': False,
                'message': 'Usage: python opencv_face_encoder.py <image_path> <student_id>',
                'data': None
            }
            print(json.dumps(error_response))
            sys.exit(1)
        
        image_path = sys.argv[1]
        student_id = sys.argv[2]
    
    # Validate we have required inputs
    if not image_path or not student_id:
        error_response = {
            'success': False,
            'message': 'Error: Both image_path and student_id are required',
            'data': None
        }
        print(json.dumps(error_response))
        sys.exit(1)
    
    # Log the inputs for debugging (goes to stderr, not stdout)
    # sys.stderr.write(f"Processing image: {image_path} for student: {student_id}\n")
    
    result = register_student_image(image_path, student_id)
    print(result)