<?php
// Prevent direct access
if (!defined('FACIAL_ATTENDANCE_SYSTEM')) {
    die('Direct access not permitted');
}
?>

    <!-- Footer -->
    <footer class="bg-dark text-light py-4 mt-auto">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h6><?php echo SYSTEM_NAME; ?></h6>
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo UNIVERSITY_NAME; ?>. All rights reserved.</p>
                    <small class="text-muted">Version <?php echo SYSTEM_VERSION; ?></small>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="mb-2">
                        <a href="#" class="text-light me-3"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="text-light me-3"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-light"><i class="fab fa-instagram"></i></a>
                    </div>
                    <small class="text-muted">
                        Developed by ICT Unit, <?php echo UNIVERSITY_SHORT; ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Chart.js for reports -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom JavaScript -->
    <script src="<?php echo ASSETS_URL; ?>js/main.js"></script>
    
    <!-- Additional JavaScript if specified -->
    <?php if (isset($additional_js)): ?>
        <?php foreach ($additional_js as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Role-specific JavaScript -->
    <?php if (SessionManager::isLoggedIn()): ?>
        <?php $role = SessionManager::getUserRole(); ?>
        <?php if ($role === ROLE_ADMIN): ?>
            <script src="<?php echo ASSETS_URL; ?>js/admin.js"></script>
        <?php elseif ($role === ROLE_LECTURER): ?>
            <script src="<?php echo ASSETS_URL; ?>js/lecturer.js"></script>
        <?php elseif ($role === ROLE_STUDENT): ?>
            <script src="<?php echo ASSETS_URL; ?>js/student.js"></script>
        <?php endif; ?>
    <?php endif; ?>

</body>
</html>