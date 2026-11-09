<?php
// Tests use the independent loopback peer from run.php; no business server.
use DB4D\{DB4DDriver,Parameters,WireParameters,SqlState,Codec};

test('binary lexer preserves literals and expands repeated named parameters',static function(){
    same(Parameters::compile("SELECT ':x', [x?], :x, :x -- :ignored\n",['x'=>42],[],1024,1024),
        ["SELECT ':x', [x?], ?, ? -- :ignored\n",['VK_LONG','VK_LONG'],'1'.hex2bin('2a000000').'1'.hex2bin('2a000000')]);
});
foreach([
    [null,null,'VK_STRING','30'],[false,null,'VK_BOOLEAN','310000'],[true,null,'VK_BOOLEAN','310100'],
    [-2147483648,null,'VK_LONG','3100000080'],[2147483647,null,'VK_LONG','31ffffff7f'],
    [PHP_INT_MIN,null,'VK_LONG8','310000000000000080'],[PHP_INT_MAX,null,'VK_LONG8','31ffffffffffffff7f'],
    [1.5,null,'VK_REAL','31000000000000f83f'],['',null,'VK_STRING','3100000000'],
    ['é',null,'VK_STRING','31ffffffffe900'],["\0",null,'VK_STRING','31ffffffff0000'],
    ["\x00\xff",PDO::PARAM_LOB,'VK_BLOB','310200000000ff'],['',PDO::PARAM_LOB,'VK_BLOB','3100000000'],
    ['00042',PDO::PARAM_INT,'VK_LONG','312a000000'],
] as $i=>[$value,$type,$wire,$hex]){
    test('fixed parameter bytes '.$i,static function()use($value,$type,$wire,$hex){same(WireParameters::encode($value,$type,1024),[$wire,hex2bin($hex)]);});
}
test('binary parameter limits reject before sending or converting unlimited streams',static function(){
    fails(static fn()=>WireParameters::encode('9223372036854775808',PDO::PARAM_INT,32),'22003');
    fails(static fn()=>WireParameters::encode('-9223372036854775809',PDO::PARAM_INT,32),'22003');
    fails(static fn()=>WireParameters::encode(INF,null,32),'22003');
    fails(static fn()=>WireParameters::encode("\xff",PDO::PARAM_STR,32),'22021');
    fails(static fn()=>WireParameters::encode('abc',PDO::PARAM_STR,4),'HY001');
    $stream=fopen('php://temp','w+');fwrite($stream,str_repeat('x',20));rewind($stream);
    fails(static fn()=>WireParameters::encode($stream,PDO::PARAM_LOB,4),'HY001');same(ftell($stream),5);fclose($stream);
    fails(static fn()=>Parameters::compile('SELECT ?,?',['abc','def'],[],100,8),'HY001');
});
test('default execution sends actual typed parameters independently of SQL',static function(){
    $step=['command'=>'EXECUTE-STATEMENT','sql'=>'INSERT INTO fixture VALUES (?,?,?)','headers'=>['PARAMETER-TYPES'=>'VK_LONG VK_STRING VK_BLOB'],
        'request_body'=>base64_encode(hex2bin('312a00000031ffffffffe900310200000000ff'))]+update('unused',1);
    peer([login(),$step],static function($port){
        $db=new DB4DDriver('127.0.0.1',$port,'fixture-user','test-password');same($db->getAttribute(PDO::ATTR_EMULATE_PREPARES),false);
        $s=$db->prepare('INSERT INTO fixture VALUES (:id,:name,:blob)');$s->bindValue('id',42,PDO::PARAM_INT);$s->bindValue('name','é');
        $s->bindValue('blob',"\0\xff",PDO::PARAM_LOB);same($s->execute(),true);same($s->rowCount(),1);
    });
});
test('reused named binary LOB reads a stream only once',static function(){
    $f=fopen('php://temp','w+');fwrite($f,'ab');rewind($f);
    same(Parameters::compile('SELECT :b,:b',['b'=>$f],['b'=>PDO::PARAM_LOB],10,20),['SELECT ?,?',['VK_BLOB','VK_BLOB'],str_repeat('1'.pack('V',2).'ab',2)]);fclose($f);
});
test('date formats distinguish Faldon PDO legacy slash and DB4D legacy',static function(){
    foreach(['pdo4d'=>'2026-11-09 01:01:01.123','pdo'=>'2026/11/09 01:01:01.123','legacy'=>'09.11.2026 01:01:01'] as $mode=>$expected){
        $b=pack('vCCV',2026,11,9,3661123);$read=static function($n)use(&$b){$v=substr($b,0,$n);$b=substr($b,$n);return $v;};
        same(Codec::value('VK_TIMESTAMP',$read,1024,$mode),$expected);
    }
});
test('default date mode matches the audited PDO_4D implementation',static function(){
    peer([login(),selectStep('SELECT ts',[[pack('vCCV',2026,11,9,123)]],['VK_TIMESTAMP'],['ts']),closeStep()],static function($port){
        $db=new DB4DDriver('127.0.0.1',$port,'fixture-user','test-password');$s=$db->query('SELECT ts');same($s->fetchColumn(),'2026-11-09 00:00:00.123');$s->closeCursor();
    });
});
test('SQLSTATE mapping retains a synchronized connection after a constraint error',static function(){
    same(SqlState::fromNative(1134),'23502');same(SqlState::fromNative(1502),'22007');same(SqlState::fromNative(999999),'HY000');
    peer([login(),['command'=>'EXECUTE-STATEMENT','status'=>'ERROR','response'=>['Error-Code'=>'1134','Error-Description'=>'PRIVATE SQL']],update('UPDATE retry',1)],static function($port){
        $db=connectPeer($port);$e=fails(static fn()=>$db->exec('bad'),'23502');same($e->errorInfo[1],1134);same(str_contains($e->getMessage(),'PRIVATE'),false);same($db->isOpen(),true);same($db->exec('UPDATE retry'),1);
    });
});
test('native cell error is retained but its incomplete result closes the channel',static function(){
    $r=result([[1]],['VK_LONG'],['n']);$r['body']=base64_encode('2'.hex2bin('4e04000000000000'));
    peer([login(),['command'=>'EXECUTE-STATEMENT']+$r],static function($port){$db=connectPeer($port);$e=fails(static fn()=>$db->query('SELECT bad'),'42S22');same($e->errorInfo[1],1102);same($db->isOpen(),false);});
});
test('column metadata exposes types names and binary flags without additional requests',static function(){
    peer([login(),selectStep('SELECT meta',[[1,'x']],['VK_LONG','VK_IMAGE'],['id','picture']),closeStep()],static function($port){
        $db=connectPeer($port);$db->setAttribute(PDO::ATTR_CASE,PDO::CASE_UPPER);$s=$db->query('SELECT meta');same($s->getColumnMeta(1)['flags'],['blob']);same($s->getColumnMeta(0)['name'],'ID');same($s->getColumnMeta(1)['native_type'],'VK_IMAGE');same($s->getColumnMeta(-1),false);same($s->getColumnMeta(2),false);$s->closeCursor();
    });
});
test('preferred image types are validated at login and execution',static function(){
    $login=login();$login['headers']=['PREFERRED-IMAGE-TYPES'=>'png,jpg'];$step=selectStep('SELECT image',[]);$step['headers']=['PREFERRED-IMAGE-TYPES'=>'png,jpg'];
    peer([$login,$step,closeStep()],static function($port){$db=connectPeer($port,[DB4DDriver::ATTR_PREFERRED_IMAGE_TYPES=>'png,jpg']);same($db->getAttribute(DB4DDriver::ATTR_PREFERRED_IMAGE_TYPES),'png,jpg');fails(static fn()=>$db->setAttribute(DB4DDriver::ATTR_PREFERRED_IMAGE_TYPES,"jpg\r\nQUIT"),'IM001');$s=$db->query('SELECT image');$s->closeCursor();});
});
test('bound columns preserve null and are assigned on each fetched row',static function(){
    peer([login(),selectStep('SELECT bound',[[1,'one'],[2,null]]),closeStep()],static function($port){$s=connectPeer($port)->query('SELECT bound');$id=0;$name='';$s->bindColumn(1,$id,PDO::PARAM_INT);$s->bindColumn('name',$name);same($s->fetch(PDO::FETCH_BOUND),true);same([$id,$name],[1,'one']);same($s->fetch(PDO::FETCH_BOUND),true);same([$id,$name],[2,null]);same($s->fetch(PDO::FETCH_BOUND),false);$s->closeCursor();});
});
class FetchPersonFixture { public int $id;public string $name;public string $seen;public function __construct(string $prefix=''){ $this->seen=$prefix.($this->name??'unset'); } }
foreach([PDO::FETCH_CLASS,PDO::FETCH_CLASS|PDO::FETCH_PROPS_LATE] as $mode){test('class hydration constructor order '.$mode,static function()use($mode){
    peer([login(),selectStep('SELECT person',[[42,'Ada']]),closeStep()],static function($port)use($mode){$s=connectPeer($port)->query('SELECT person');$rows=$s->fetchAll($mode,FetchPersonFixture::class,['prefix:']);same($rows[0]->id,42);same($rows[0]->name,'Ada');same($rows[0]->seen,$mode===PDO::FETCH_CLASS?'prefix:Ada':'prefix:unset');$s->closeCursor();});
});}
test('fetchObject consumes one row with a declared class then reports EOF',static function(){peer([login(),selectStep('SELECT object',[[42,'Ada']]),closeStep()],static function($port){$s=connectPeer($port)->query('SELECT object');same($s->fetchObject(FetchPersonFixture::class)->name,'Ada');same($s->fetchObject(),false);$s->closeCursor();});});
foreach([PDO::FETCH_COLUMN|PDO::FETCH_GROUP,PDO::FETCH_ASSOC|PDO::FETCH_GROUP,PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE,PDO::FETCH_KEY_PAIR] as $mode){test('grouped and key pair mode '.$mode,static function()use($mode){
    peer([login(),selectStep('SELECT grouped',[[1,'a'],[1,'b'],[2,'c']]),closeStep()],static function($port)use($mode){$s=connectPeer($port)->query('SELECT grouped');$expected=match($mode){PDO::FETCH_COLUMN|PDO::FETCH_GROUP=>[1=>['a','b'],2=>['c']],PDO::FETCH_ASSOC|PDO::FETCH_GROUP=>[1=>[['name'=>'a'],['name'=>'b']],2=>[['name'=>'c']]],PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE=>[1=>['name'=>'b'],2=>['name'=>'c']],PDO::FETCH_KEY_PAIR=>[1=>'b',2=>'c']};same($s->fetchAll($mode),$expected);$s->closeCursor();});
});}
test('named fetch preserves duplicate column names',static function(){peer([login(),selectStep('SELECT duplicate',[[1,2]],['VK_LONG','VK_LONG'],['id','id']),closeStep()],static function($port){$s=connectPeer($port)->query('SELECT duplicate');same($s->fetch(PDO::FETCH_NAMED),['id'=>[1,2]]);$s->closeCursor();});});
test('functional fetch receives columns in order',static function(){peer([login(),selectStep('SELECT callback',[[1,'a'],[2,'b']]),closeStep()],static function($port){$s=connectPeer($port)->query('SELECT callback');same($s->fetchAll(PDO::FETCH_FUNC,static fn($id,$name)=>$id.$name),['1a','2b']);$s->closeCursor();});});

test('graceful logout is explicit and idempotent',static function(){peer([login(),['command'=>'LOGOUT'],['command'=>'QUIT']],static function($port){$db=connectPeer($port);$db->disconnect();same($db->isOpen(),false);$db->disconnect();});});
