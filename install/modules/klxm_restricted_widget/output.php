<?php
use KLXM\Restricted\Frontend\UserWidget;

if (!rex_addon::get('klxm_restricted')->isAvailable()) {
    return;
}

$widget = new UserWidget();
echo $widget->render();
