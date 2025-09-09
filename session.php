<?php 

session_start();

function flash($key, $message = null)
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return;
    }

    if (isset($_SESSION['_flash'][$key])) {
        $value = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    return null;
}