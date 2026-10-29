<?php

use App\Http\Middleware\BrowserSession;
use App\Models\Membership;
use App\Models\User;
use App\Services\SecurityInventory;
use App\Support\Api;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') { exit(3); }
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$m = Membership::with(['school','user'])->findOrFail($in['membership']);
$session = app('session')->driver(); $session->setId($in['session']); $session->start(); request()->setLaravelSession($session);
Auth::guard('web')->login($m->user); $session->setId($in['session']); request()->setUserResolver(fn () => $m->user);
$session->put(['school_id'=>$m->school_id,'credential_hash'=>hash('sha256',$m->user->password),'issued_at'=>time(),'seen_at'=>time()]);
file_put_contents($in['ready'],'ready'); $deadline=microtime(true)+15;
while(!is_file($in['gate'])) { if(microtime(true)>$deadline)exit(4);usleep(10000); }
try {
 switch($in['operation']) {
 case 'login':
   Auth::guard('web')->logout(); app('auth')->forgetGuards();
   $req=Illuminate\Http\Request::create('/api/v1/auth/login','POST',['school_code'=>$m->school->code,'identifier'=>$m->login_identifier,'password'=>'Synthetic-Race-Old-Password']);
   $req->headers->set('Accept','application/json');
   $response=app(Illuminate\Contracts\Http\Kernel::class)->handle($req);
   echo $response->getStatusCode()===200?'issued':'denied'; break;
 case 'password':
   $u=User::findOrFail($m->user_id);$u->password='Synthetic-Race-New-Password';$u->save();echo 'changed';break;
 case 'admin-demote':
   $m->role='principal';$m->save();echo 'changed';break;
 case 'membership':
   $m->status='inactive';$m->save();echo 'changed';break;
 case 'device-revoke':
   app(SecurityInventory::class)->revoke($m,request(),'Synthetic-Race-Old-Password','device',$in['target']);echo 'revoked';break;
 case 'session-revoke':
   app(SecurityInventory::class)->revoke($m,request(),'Synthetic-Race-Old-Password','session',$in['target']);file_put_contents($in['done'],'done');echo 'revoked';break;
 case 'protected-session':
   // A request can already be admitted while another process revokes it. Simulate the
   // real session handler's late write, then test denial on the following request.
   $response=app(BrowserSession::class)->handle(request(),fn()=>Api::ok(['allowed'=>true]));
   while(!is_file($in['done'])) { if(microtime(true)>$deadline)exit(5);usleep(10000); }
   DB::table('sessions')->updateOrInsert(['id'=>$in['session']],['user_id'=>$m->user_id,'payload'=>'synthetic-late-writer','last_activity'=>time()]);
   echo $response->getStatusCode()===200?'admitted':'denied';break;
 }
} catch(HttpException|\Illuminate\Validation\ValidationException $e) { echo 'denied'; }
