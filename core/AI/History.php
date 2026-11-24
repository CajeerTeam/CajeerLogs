<?php
declare(strict_types=1);
namespace Cajeer\Logs\AI;
use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Never persists raw context, stack traces, credentials, or provider request bodies. */
final class History
{
    public const LIMIT=30;
    public function __construct(private readonly PDO $pdo){}
    private function sql(string $sql,array $params): \PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($params);return $s;}
    public function save(string $user,string $project,string $question,array $result): string
    {
        // Serialize per-owner saves, so concurrent requests cannot exceed the history cap.
        return (new TransactionManager($this->pdo))->run(function()use($user,$project,$question,$result):string{
            $this->sql("SELECT pg_advisory_xact_lock(hashtextextended('cajeerlogs:ai-history:'||current_schema()||':'||CAST(:user AS text)||':'||CAST(:project AS text),0))",['user'=>$user,'project'=>$project])->closeCursor();
            $data=json_encode(array_intersect_key($result,array_flip(['answer','usage','evidence','truncated','messages_included','model','investigation','incidents','window'])),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $id=$this->sql('INSERT INTO ai_investigation_history(user_id,project_id,question,result) VALUES(:user,:project,:question,CAST(:result AS jsonb)) RETURNING id',['user'=>$user,'project'=>$project,'question'=>Assistant::mask($question),'result'=>$data])->fetchColumn();
            $this->sql('DELETE FROM ai_investigation_history WHERE user_id=:user AND project_id=:project AND (created_at<=clock_timestamp()-interval \'30 days\' OR id IN (SELECT id FROM ai_investigation_history WHERE user_id=:owner AND project_id=:scope ORDER BY created_at DESC,id DESC OFFSET '.self::LIMIT.'))',['user'=>$user,'project'=>$project,'owner'=>$user,'scope'=>$project]);
            if(!is_string($id)||$id==='')throw new \RuntimeException('Investigation history insert failed.');
            return $id;
        });
    }
    public function list(string $user,string $project): array
    {
        return $this->sql("SELECT id,to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS created_at,left(question,160) AS question FROM ai_investigation_history WHERE user_id=:user AND project_id=:project AND created_at>clock_timestamp()-interval '30 days' ORDER BY created_at DESC,id DESC LIMIT ".self::LIMIT,['user'=>$user,'project'=>$project])->fetchAll(PDO::FETCH_ASSOC);
    }
    public function get(string $user,string $project,string $id): array
    {
        $row=$this->sql("SELECT id,to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS created_at,question,result FROM ai_investigation_history WHERE user_id=:user AND project_id=:project AND id=:id AND created_at>clock_timestamp()-interval '30 days'",['user'=>$user,'project'=>$project,'id'=>$id])->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new HttpException(404,'not_found','Investigation not found.');
        $row['result']=json_decode($row['result'],true,64,JSON_THROW_ON_ERROR);$row['result']['usage']=(object)$row['result']['usage'];return $row;
    }
    public function delete(string $user,string $project,string $id): void
    {
        if($this->sql('DELETE FROM ai_investigation_history WHERE user_id=:user AND project_id=:project AND id=:id',['user'=>$user,'project'=>$project,'id'=>$id])->rowCount()!==1)throw new HttpException(404,'not_found','Investigation not found.');
    }
}
