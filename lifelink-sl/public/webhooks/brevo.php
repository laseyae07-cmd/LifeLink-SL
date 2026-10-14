<?php
require_once dirname(dirname(__DIR__)).'/src/bootstrap.php';
$expected=(string)config('brevo','webhook_token');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
if($expected==='' || !hash_equals('Bearer '.$expected,$auth)){http_response_code(401);exit;}
$payload=json_decode(file_get_contents('php://input')?:'{}',true);$messageId=(string)($payload['message-id']??$payload['messageId']??'');$event=(string)($payload['event']??'');if($messageId&&$event){$stmt=db()->prepare('UPDATE notification_logs SET delivery_status=?,updated_at=NOW() WHERE provider_message_id=?');$stmt->execute([substr($event,0,50),$messageId]);}http_response_code(204);
