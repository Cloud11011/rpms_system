<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Loading...</title>

    <link rel="icon" type="image/png" href="assets/images/prismicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

<div class="background-overlay">

    <div class="loading-card">

        <img src="assets/images/prismlogo1.png" class="loading-logo" alt="PRISM logo">

        <div class="prism-branding">
            <h1>Welcome to <span>PRISM</span>!</h1>
            <p><strong>IERB Progress &amp; Reporting System</strong></p>
            <p>Centro Escolar University - Malolos <span aria-hidden="true">&bull;</span> RPMS</p>
        </div>

        <div class="loading-bar">

            <div class="loading-progress"></div>

        </div>

    </div>

</div>

<script>

var destination = <?php
    require __DIR__ . '/config.php';
    $role = $_SESSION['account_type'] ?? null;
    $target = $role === 'student' ? 'student.php' : ($role === 'adviser' ? 'ierbprog.php' : 'dashboard.php');
    echo json_encode($target);
?>;

setTimeout(function(){

    window.location.href = destination;

},1500);

</script>

</body>

</html>
