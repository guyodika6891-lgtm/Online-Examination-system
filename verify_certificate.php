<?php
require_once 'config/database.php';
require_once 'includes/certificate_generator.php';

$verification_result = null;
$verification_code = isset($_GET['code']) ? $_GET['code'] : (isset($_POST['code']) ? $_POST['code'] : '');

if($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($verification_code)) {
    $verification_result = verifyCertificate($pdo, $verification_code);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Certificate - Online Examination System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container { max-width: 500px; width: 100%; }
        .card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        h2 { color: #333; margin-bottom: 20px; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; text-transform: uppercase; }
        button { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; }
        .result { margin-top: 20px; padding: 15px; border-radius: 8px; }
        .valid { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .invalid { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .cert-info { margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px; }
        .cert-info p { margin: 8px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>🔍 Certificate Verification</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Enter Verification Code</label>
                    <input type="text" name="code" placeholder="e.g., ABC123DEF4" value="<?php echo htmlspecialchars($verification_code); ?>" required>
                </div>
                <button type="submit">Verify Certificate</button>
            </form>
            
            <?php if($verification_result): ?>
                <div class="result valid">
                    <strong>✓ VALID CERTIFICATE</strong>
                    <div class="cert-info">
                        <p><strong>Certificate Number:</strong> <?php echo $verification_result['certificate_no']; ?></p>
                        <p><strong>Issued To:</strong> <?php echo htmlspecialchars($verification_result['full_name']); ?></p>
                        <p><strong>Exam:</strong> <?php echo htmlspecialchars($verification_result['exam_name']); ?></p>
                        <p><strong>Issue Date:</strong> <?php echo date('F j, Y', strtotime($verification_result['issue_date'])); ?></p>
                        <p><strong>Status:</strong> <span style="color: #28a745;">Active</span></p>
                    </div>
                </div>
            <?php elseif($_SERVER['REQUEST_METHOD'] == 'POST' && empty($verification_result)): ?>
                <div class="result invalid">
                    <strong>✗ INVALID CERTIFICATE</strong>
                    <p>No certificate found with this verification code. Please check the code and try again.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>