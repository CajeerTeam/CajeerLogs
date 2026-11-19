<?php
declare(strict_types=1);
/** Execute the shipped router, not a list hand-maintained for documentation. */
use Cajeer\Logs\Http\{Router,Request,ErrorHandler,HttpException};
use Cajeer\Logs\Kernel\Application;
require dirname(__DIR__).'/core/bootstrap.php';
$root=dirname(__DIR__);
$app=new Application($root);
$app->boot();
$property=new ReflectionProperty(Router::class,'routes');
$registered=$property->getValue($app->container()->get(Router::class));
$prefix=(string)$app->config()->get('api.prefix');
$actual=[];
foreach ($registered as $route) {
    if (str_starts_with($route['path'],$prefix.'/')) {
        $actual[]=['method'=>$route['method'],'path'=>substr($route['path'],strlen($prefix))];
    }
}
foreach ($actual as $operation) {
    $path=preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/','7',$operation['path']);
    $request=new Request('OPTIONS',$prefix.$path);
    $response=$app->handle($request);
    if ($response->status!==204 || !str_contains(', '.($response->header('Allow')??'').',', ', '.$operation['method'].',')) {
        throw new RuntimeException('OPTIONS or Allow is incompatible: '.$operation['method'].' '.$operation['path']);
    }
    if ($response->body!=='' || $response->header('X-Request-ID')!==$request->requestId) {
        throw new RuntimeException('OPTIONS response framing is incompatible.');
    }
}
$unknown=$app->handle(new Request('GET',$prefix.'/nonexistent-v1-route'));
if ($unknown->status!==404) throw new RuntimeException('Unknown API route must return 404.');
$method=$app->handle(new Request('PATCH',$prefix.'/health'));
if ($method->status!==405 || !str_contains((string)$method->header('Allow'),'GET')) throw new RuntimeException('405 and Allow contract changed.');
$badPath=$app->handle(new Request('GET',$prefix.'/ingest/jobs/%2F'));
if ($badPath->status!==400) throw new RuntimeException('Unsafe route parameter must return 400.');
$errorResponses=[
    $unknown,$method,$badPath,
    ErrorHandler::decorate(ErrorHandler::response(new HttpException(422,'invalid_payload','Invalid payload.'), '123abc'), '123abc'),
    ErrorHandler::decorate(ErrorHandler::response(new HttpException(503,'database_unavailable','Sensitive internal text.'), '123abc'), '123abc'),
    ErrorHandler::decorate(ErrorHandler::response(new RuntimeException('Sensitive details.'), '123abc'), '123abc'),
];
$errors=[];
foreach ($errorResponses as $response) {
    $value=json_decode($response->body,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($value) || !is_string($value['error']??null) || !is_string($value['message']??null)
        || !is_string($value['request_id']??null) || $value['request_id']==='' || array_diff(array_keys($value),['error','message','request_id','details'])!==[]
        || $response->header('X-Request-ID')!==$value['request_id']
        || !str_contains((string)$response->header('Content-Type'),'application/json')) {
        throw new RuntimeException('Unexpected API error envelope or request ID header.');
    }
    if (str_contains($response->body,'Sensitive')) throw new RuntimeException('Internal error leaked exception data.');
    $errors[]=['status'=>$response->status,'body'=>$value];
}
echo json_encode(['operations'=>$actual,'errors'=>$errors,'checks'=>count($actual)+count($errors)+3],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),"\n";
