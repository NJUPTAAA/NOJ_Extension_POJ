<?php
namespace App\Babel\Extension\poj;

use App\Babel\Submit\Curl;
use App\Models\Submission\SubmissionModel;
use App\Models\JudgerModel;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict=[
        'Accepted'=>"Accepted",
        "Presentation Error"=>"Presentation Error",
        'Time Limit Exceeded'=>"Time Limit Exceed",
        "Memory Limit Exceeded"=>"Memory Limit Exceed",
        'Wrong Answer'=>"Wrong Answer",
        'Runtime Error'=>"Runtime Error",
        'Output Limit Exceeded'=>"Output Limit Exceeded",
        'Compile Error'=>"Compile Error",
        'System Error'=>"System Error",
    ];
    private $model=[];
    private $poj=[];


    public function __construct()
    {
        $this->model["submissionModel"]=new SubmissionModel();
        $this->model["judgerModel"]=new JudgerModel();
    }

    public function judge($row)
    {
        $sub=[];

        if (!isset($this->poj[$row['remote_id']])) {
            $judgerDetail=$this->model["judgerModel"]->detail($row['jid']);
            $this->appendPOJStatus($judgerDetail['handle'], $row['remote_id']);
            if (!isset($this->poj[$row['remote_id']])) {
                return;
            }
        }

        $status=$this->poj[$row['remote_id']];

        if(!isset($this->verdict[$status['verdict']])) {
            return ;
        }
        
        $sub['verdict']=$this->verdict[$status['verdict']];

        if ($sub['verdict']=='Compile Error') {
            try {
                $res=Requests::get('http://poj.org/showcompileinfo?solution_id='.$row['remote_id']);
                preg_match('/<pre>([\s\S]*)<\/pre>/', $res->body, $match);
                $sub['compile_info']=html_entity_decode($match[1], ENT_QUOTES);
            } catch (Exception $e) {
            }
        }

        $sub["score"]=$sub['verdict']=="Accepted" ? 1 : 0;
        $sub['time']=$status['time'];
        $sub['memory']=$status['memory'];
        $sub['remote_id']=$row['remote_id'];

        $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
    }

    private function appendPOJStatus($judger, $first=null)
    {
        if ($first!==null) {
            $first++;
        }
        $res = $this->grab_page([
            'site' => "http://poj.org/status?user_id={$judger}&top={$first}",
            'oj' => 'poj',
            'handle' => $judger,
        ]);
        // Timed out for no reason
        // $res=Requests::get("http://poj.org/status?user_id={$judger}&top={$first}");
        $rows=preg_match_all('/<tr align=center><td>(\d+)<\/td><td>.*?<\/td><td>.*?<\/td><td>.*?<font color=.*?>(.*?)<\/font>.*?<\/td><td>(\d*)K?<\/td><td>(\d*)(?:MS)?<\/td>/', $res, $matches);
        for ($i=0; $i<$rows; $i++) {
            $this->poj[$matches[1][$i]]=[
                'verdict'=>$matches[2][$i],
                'memory'=>$matches[3][$i] ? $matches[3][$i] : 0,
                'time'=>$matches[4][$i] ? $matches[4][$i] : 0,
            ];
        }
    }
}
