<?php
require_once 'config/multi_role.php';

$user_id = $_SESSION['user_id'];
$available_roles = getAvailableRoles($pdo, $user_id);
$current_role = getCurrentRole();

// Handle role switching
if(isset($_POST['switch_role']) && isset($_POST['new_role'])) {
    $new_role = $_POST['new_role'];
    if(switchRole($pdo, $user_id, $new_role)) {
        // Redirect to the appropriate dashboard based on new role
        $redirect_map = [
            'admin' => 'admin/dashboard.php',
            'exam_committee' => 'exam_committee/dashboard.php',
            'teacher' => 'teacher/dashboard.php',
            'student' => 'student/dashboard.php'
        ];
        header("Location: ../" . ($redirect_map[$new_role] ?? 'index.php'));
        exit();
    }
}
?>

<?php if(count($available_roles) > 1): ?>
<div class="role-switcher">
    <form method="POST" style="display: inline;">
        <label style="margin-right: 5px;">🎭 Switch Role:</label>
        <select name="new_role" onchange="this.form.submit()" style="padding: 5px 10px; border-radius: 5px; border: 1px solid #ddd;">
            <?php foreach($available_roles as $role): ?>
            <option value="<?php echo $role; ?>" <?php echo $role == $current_role ? 'selected' : ''; ?>>
                <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="switch_role" value="1">
    </form>
</div>
<?php endif; ?>