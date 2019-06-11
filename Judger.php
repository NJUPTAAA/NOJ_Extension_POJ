<?php
namespace App\Babel\Extension\poj;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
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
    ];
    private $MODEL;

    public function __construct()
    {
        $this->MODEL=new SubmissionModel();
    }

    public function judge($row)
    {
        $sub=[];
        if (!isset($poj[$row['remote_id']])) {
            $judgerDetail=$judger->detail($row['jid']);
            $this->appendPOJStatus($poj, $judgerDetail['handle'], $row['remote_id']);
            if (!isset($poj[$row['remote_id']])) {
                return;
            }
        }
        $status=$poj[$row['remote_id']];
        $sub['verdict']=$verdict[$status['verdict']];
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

        // $ret[$row['sid']]=[
        //     "verdict"=>$sub['verdict']
        // ];

        $this->MODEL->updateSubmission($row['sid'], $sub);
    }
}
