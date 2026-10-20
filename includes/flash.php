<?php
/** Render queued flash messages as dismissible alerts. */
function render_flashes(): void
{
    $flashes = take_flashes();
    if (!$flashes) {
        return;
    }
    foreach ($flashes as $f) {
        $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        $iconName = ['success' => 'check-circle', 'danger' => 'alert', 'warning' => 'alert', 'info' => 'info'][$type];
        echo '<div class="alert alert--' . e($type) . '" role="status" data-dismissible>';
        echo '<span class="alert__icon">' . icon($iconName, 'icon', 18) . '</span>';
        echo '<span class="alert__text">' . e($f['message']) . '</span>';
        echo '<button type="button" class="alert__close" data-dismiss aria-label="Dismiss">&times;</button>';
        echo '</div>';
    }
}
