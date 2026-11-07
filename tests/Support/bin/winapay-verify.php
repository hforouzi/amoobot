<?php

declare(strict_types=1);

/*
 * Test helper: one side of the race between a Winapay callback and the reconcile, in its own PHP process (own DB
 * connection, own lock wait). The answers come from the gateway's script (ScriptedWinapayPlugin), which processes share.
 * Usage: php winapay-verify.php <callback|reconcile> <paymentId> <authority> <startAt>
 * Prints what happened, "callback:<code>" or "reconcile:<outcome>".
 */

use App\Entity\PaymentGateway;
use App\Payment\Application\Reconcile\WinapayReconciler;
use App\Payment\Application\Webhook\GatewayNotificationProcessor;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use Symfony\Component\HttpFoundation\Request;

[$container, $entityManager] = require __DIR__.'/boot-test-kernel.php';
require __DIR__.'/start-at.php';

// From $_SERVER['argv'], checked: phpstan cannot tell that $argv is defined here.
$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || count($arguments) < 5) {
    fwrite(STDERR, "usage: winapay-verify.php <callback|reconcile> <paymentId> <authority> <startAt>\n");
    exit(2);
}
[, $side, $paymentId, $authority, $startAt] = array_map(strval(...), $arguments);
waitForStartAt($startAt);

if ('callback' === $side) {
    $gateways = $entityManager->getRepository(PaymentGateway::class)->findBy(['pluginCode' => 'winapay', 'isActive' => true]);
    $request = Request::create('/payment/callback/plugin/winapay', 'POST', ['Authority' => $authority, 'PaymentStatus' => 'OK']);
    $outcome = $container->get(GatewayNotificationProcessor::class)->process($gateways, $request, NotificationAwareGatewayInterface::CHANNEL_CALLBACK, 'plugin_winapay_callback');

    echo 'callback:'.$outcome->code;
} else {
    echo 'reconcile:'.$container->get(WinapayReconciler::class)->reconcile((int) $paymentId)->value;
}
