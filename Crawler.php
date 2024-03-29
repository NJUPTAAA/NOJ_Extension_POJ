<?php
namespace App\Babel\Extension\poj;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid=null;
    public $prefix="POJ";
    private $con;
    private $action;
    private $cached;
    private $imgi;
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $this->action=isset($conf["action"])?$conf["action"]:'crawl_problem';
        $this->cached=isset($conf["cached"])?$conf["cached"]:false;
        $con=isset($conf["con"])?$conf["con"]:'all';
        $this->oid=OJModel::oid('poj');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($this->action=='judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con);
        }
    }

    public function judge_level()
    {
        // TODO
    }

    private static function find($pattern, $subject)
    {
        if (preg_match($pattern, $subject, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function getDOM($html, $start, $end)
    {
        if ($start===false || $end===false) {
            throw new Exception("Missing keywords.");
        }
        return $this->cacheImage(HtmlDomParser::str_get_html(substr($html, $start, $end-$start), true, true, DEFAULT_TARGET_CHARSET, false));
    }

    private function getInnertext($html, $start, $end, $tag)
    {
        return $this->getDOM($html, $start, $end)->find($tag, 0)->innertext();
    }

    private function cacheImage($dom)
    {
        foreach ($dom->find('img') as $ele) {
            $src=str_replace('\\', '/', $ele->src);
            if (strpos($src, '://')!==false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='http://poj.org'.$src;
            } else {
                $url='http://poj.org/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'http://poj.org']);
            $ext=['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/gif'=>'.gif', 'image/bmp'=>'.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext=$ext[$res->headers['content-type']];
            } else {
                $pos=strpos($ele->src, '.');
                if ($pos===false) {
                    $cext='';
                } else {
                    $cext=substr($ele->src, $pos);
                }
            }
            $fn=$this->con.'_'.($this->imgi++).$cext;
            $dir=base_path("public/external/poj/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/poj/img/$fn"), $res->body);
            $ele->src='/external/poj/img/'.$fn;
        }
        return $dom;
    }

    public function crawl($con)
    {
        if($con=='all'){
            $lastProbID=4054;
            $POJVolume=HtmlDomParser::str_get_html("<center><font size=5>Volume</font>".explode("<font size=5>Volume</font>",Requests::get('http://poj.org/problemlist', ['Referer' => 'http://poj.org'])->body)[1], true, true, DEFAULT_TARGET_CHARSET, false);
            $lastVolume = $POJVolume->find('center')[0]->last_child()->plaintext;
            $POJVolumePage=HtmlDomParser::str_get_html(Requests::get("http://poj.org/problemlist?volume=$lastVolume", ['Referer' => 'http://poj.org'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
            $lastProbID=intval($POJVolumePage->find("tr[align='center']", -1)->find("td", 0)->plaintext);
            foreach (range(1000, $lastProbID) as $probID) {
                $this->_crawl($probID, 5);
            }
        }else{
            $this->_crawl($con, 5);
        }
    }

    protected function _crawl($con, $retry=1)
    {
        $attempts=1;
        while($attempts <= $retry){
            try{
                $this->__crawl($con);
            }catch(Exception $e){
                $attempts++;
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()} at {$e->getFile()}:{$e->getLine()}</>\n");
                continue;
            }
            break;
        }
    }

    protected function __crawl($con)
    {
        $this->_resetPro();
        $this->imgi=1;
        $this->con=$con;
        $problemModel=new ProblemModel();
        if(!empty($problemModel->basic($problemModel->pid($this->prefix.$con))) && $this->action=="update_problem"){
            return;
        }
        if($this->action=="crawl_problem") $this->line("<fg=yellow>Crawling:   </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=yellow>Updating:   </>{$this->prefix}{$con}");
        else return;
        $res=Requests::get("http://poj.org/problem?id={$con}&lang=zh-CN&change=true"); // I have no idea what does `change` refers to
        if (strpos($res->body, 'Can not find problem')!==false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Can not find problem.</>\n");
            throw new Exception("Can not find problem");
        }
        $this->pro['pcode']=$this->prefix.$con;
        $this->pro['OJ']=$this->oid;
        $this->pro['contest_id']=null;
        $this->pro['index_id']=$con;
        $this->pro['origin']="http://poj.org/problem?id={$con}&lang=zh-CN&change=true";
        $this->pro['title']=self::find('/<div class="ptt" lang=".*?">([\s\S]*?)<\/div>/', $res->body);
        $this->pro['time_limit']=self::find('/Time Limit:.*?(\d+)MS/', $res->body);
        $this->pro['memory_limit']=self::find('/Memory Limit:.*?(\d+)K/', $res->body);
        $this->pro['solved_count']=self::find('/Accepted:.*?(\d+)/', $res->body);
        $this->pro['input_type']='standard input';
        $this->pro['output_type']='standard output';
        $descPattern='<p class="pst">Description</p>';
        $inputPattern='<p class="pst">Input</p>';
        $outputPattern='<p class="pst">Output</p>';
        $sampleInputPattern='<p class="pst">Sample Input</p>';
        $sampleOutputPattern='<p class="pst">Sample Output</p>';
        $notePattern='<p class="pst">Hint</p>';
        $sourcePattern='<p class="pst">Source</p>';
        $endPattern='</td>';

        $pos1=strpos($res->body, $descPattern)+strlen($descPattern);
        $pos2=strpos($res->body, $inputPattern, $pos1);
        $this->pro['description']=trim($this->getInnertext($res->body, $pos1, $pos2, 'div'));
        $pos1=$pos2+strlen($inputPattern);
        $pos2=strpos($res->body, $outputPattern, $pos1);
        $this->pro['input']=trim($this->getInnertext($res->body, $pos1, $pos2, 'div'));
        $pos1=$pos2+strlen($outputPattern);
        $pos2=strpos($res->body, $sampleInputPattern, $pos1);
        $this->pro['output']=trim($this->getInnertext($res->body, $pos1, $pos2, 'div'));
        $pos1=$pos2+strlen($sampleInputPattern);
        $pos2=strpos($res->body, $sampleOutputPattern, $pos1);
        $sampleInput=$this->getInnertext($res->body, $pos1, $pos2, 'pre');
        $pos1=$pos2+strlen($sampleOutputPattern);
        $pos2=strpos($res->body, $notePattern, $pos1);
        if ($hasNote=($pos2!==false)) {
            $sampleOutput=$this->getInnertext($res->body, $pos1, $pos2, 'pre');
            $pos1=$pos2+strlen($notePattern);
        }
        $pos2=strpos($res->body, $sourcePattern, $pos1);
        $temp=$this->getDOM($res->body, $pos1, $pos2);
        if ($hasNote) {
            $this->pro['note']=trim($temp->find('div', 0)->innertext());
        } else {
            $sampleOutput=$temp->find('pre', 0)->innertext();
            $this->pro['note']=null;
        }
        $this->pro['sample']=[['sample_input'=>$sampleInput, 'sample_output'=>$sampleOutput]];
        $pos1=$pos2+strlen($sourcePattern);
        $pos2=strpos($res->body, $endPattern, $pos1);
        $this->pro['source']=trim($this->getDOM($res->body, $pos1, $pos2)->find('div', 0)->find('a', 0)->innertext());

        $problem=$problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->updateProblem($this->oid);
        } else {
            $new_pid=$this->insertProblem($this->oid);
        }

        if($this->action=="crawl_problem") $this->line("<fg=green>Crawled:    </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=green>Updated:    </>{$this->prefix}{$con}");
    }
}
