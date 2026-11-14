<?php
declare(strict_types=1);
require dirname(__DIR__).'/core/bootstrap.php';
use Cajeer\Logs\Integration\WebhookDispatcher;
$count=0;
function check(bool $ok,string $message): void {global $count;if (!$ok) throw new RuntimeException('FAIL '.$message);$count++;echo "PASS $message\n";}
check(WebhookDispatcher::host('https://hooks.example.com/receive')==='hooks.example.com','valid HTTPS webhook');
foreach (['http://example.com','https://127.0.0.1/x','https://[::1]/','https://user@example.com/','https://example.com:8443/','https://example.com/?secret=x','https://example.com/#x','https://localhost/','https://2130706433/'] as $url) {
    try {WebhookDispatcher::host($url);check(false,'invalid URL rejected');} catch(InvalidArgumentException) {check(true,'invalid URL rejected');}
}
foreach (['127.0.0.1','10.0.0.1','192.168.0.1','169.254.169.254','172.16.0.1','100.64.0.1','0.0.0.0','224.0.0.1','::1','192.0.2.1'] as $ip) check(!WebhookDispatcher::publicIp($ip),'nonpublic address rejected '.$ip);
check(WebhookDispatcher::publicIp('8.8.8.8'),'public IPv4 allowed');
$body='{"event":"logs.ingested"}';$secret=str_repeat('a',32);$id='11111111-1111-4111-8111-111111111111';$ts='1767225600';
check(WebhookDispatcher::signature($id,$ts,$body,$secret)===hash_hmac('sha256',"cajeerlogs-webhook-v1\n$id\n$ts\n".hash('sha256',$body),$secret),'canonical webhook signature');
check(WebhookDispatcher::signature($id,$ts,$body,$secret)!==WebhookDispatcher::signature($id,$ts,$body.' ',$secret),'signature binds exact body');
echo "Webhook security: $count checks passed\n";
