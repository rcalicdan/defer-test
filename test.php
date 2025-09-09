<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>
    <?php
    require 'session.php';
    if ($msg = flash('success')) {
        echo "<p style='color: green;'>{$msg}</p>";
    }
    ?>
    <form action="/backend.php" method="POST">
        <button type="submit">Submit</button>
    </form>
</body>

</html>