<?php
use KLXM\Restricted\Frontend\LoginController;

if (!rex_addon::get('klxm_restricted')->isAvailable()) {
    return;
}

echo LoginController::processRequest();
