<?php
/**
 * Created by PhpStorm.
 * User: wuchuguang
 * Date: 17-2-17
 * Time: 下午6:13
 */

namespace app\index\service;

use app\common\cache\Cache;
use app\common\cache\TaskWorker as Worker;
use app\common\exception\JsonErrorException;
use app\common\exception\TaskException;
use app\index\cache\TaskWorkerLog;
use Carbon\Carbon;
use think\queue\command\Work;


class TaskWorker
{
    const RUN_STATUS_NOT_BEGIN = 0;//未启动过
    const RUN_STATUS_DOING = 1;//准备处理
    const RUN_STATUS_FINISH = 2;//完成处理
    const RUN_STATUS_WAITNEXT = 3;//等待下次处理
    const RUN_STATUS_END = 4;//结束任务
    const RUN_STATUS_ERROR = 5;//运行时报错


    private $worker;
    /**
     * @var AbsTasker
     */
    private $tasker;
    /**
     * @var \app\common\cache\driver\TaskWorker
     */
    private $taskWorkerCache;
    private $taskServer;

    public function __construct(Worker $worker = null)
    {
        $this->worker = $worker;
        $this->tasker = null;
        $this->taskServer = new Task();
        $this->taskWorkerCache = Cache::store('taskWorker');
    }

    public function createWorker($params)
    {
        $task = $this->taskServer->getTasker($params['task_id']);
        if(!$task){
            throw new JsonErrorException("不存在的任务");
        }
        if(!($task instanceof AbsTasker)){
            throw new JsonErrorException("不合格的任务");
        }
        $task->setData($params['param']);
        $task->checkRule();
        if($params['type'] != 1 && $params['type'] != 0){
            if($this->taskServer->isExistGlobal($params['type'])){
                throw new TaskException('全局任务已存在',2);
            }
        }
        $begin = Carbon::parse($params['begin'])->getTimestamp();
        $end = Carbon::parse($params['end'])->getTimestamp();
        $this->worker = new Worker();
        $this->worker->task_id = $params['task_id'];
        $this->worker->name = $params['name'];
        $this->worker->loop_type = $params['loop_type'];
        $this->worker->loop_value = $params['loop_value'];
        $this->worker->begin = $begin;
        $this->worker->end = $end;
        $this->worker->created_at = time();
        $this->worker->updated_at = time();
        $this->worker->max_count = $params['max_count'];
        $this->worker->use_count = 0;
        $this->worker->type = $params['type'];
        $this->worker->mode = $params['mode'];
        $this->worker->param = $params['param'];
        $this->worker->run_status = static::RUN_STATUS_NOT_BEGIN;
        $this->worker->run_tag = millisecond();
        $this->worker->status = !!$params['status'];
        $this->worker->deleted_at = '';
        $this->resetRunTag(false);
        $this->worker->save();
    }

    public function changeStatus($status)
    {
        $this->worker->status = $status;
        $this->worker->save();
    }

    public static function get($workerId)
    {
        $worker = Worker::get($workerId);
        return new static($worker);
    }

    public function exist()
    {
        return !!$this->worker;
    }

    public function destory()
    {
        if($this->worker->deleted_at > 0){
            return false;
        }else{
            $this->remScheduler();
            $this->worker->deleted_at = time();
            $this->worker->save();
            $cache = Cache::moduleStore('taskWorkerLog');
            $cache->remove($this->worker->id);
            return true;
        }
    }

    public function setRunTag($time)
    {
        $this->worker->run_tag = $time;
        $this->worker->save();
        $key = "{$this->worker->id}|{$this->worker->name}";
        if(!$this->taskWorkerCache->remWorker($key)){
            //echo "removeWorker $key not success\n";
        }
        if(!$this->taskWorkerCache->scoreWorker($key, $time)){
            //echo "scoreWorker $key not success\n";
        }
    }
    
    public function remScheduler(){
        $key = "{$this->worker->id}|{$this->worker->name}";
        return $this->taskWorkerCache->remWorker($key);
    }

    public function resetRunTag($autoSave = true)
    {
        if(!$this->worker){
            throw new JsonErrorException("非法操作");
        }
        if($this->worker->loop_type < 10){
            $scope = max(millisecond() + 3000, $this->worker->begin * 1000 + 2000);
        }else{
            $beginTime = max($this->worker->begin, time());
            $now = Carbon::createFromTimestamp($beginTime);
            switch ($this->worker->loop_type){
                case 11:
                    $now->endOfWeek();
                    $now->hour = 0;
                    $now->minute = 0;
                    $now->second = 0;
                    $now->addSecond($this->worker->loop_value);
                    $scope = $now->getTimestamp() * 1000;
                    break;
                case 12:
                    $now = $now->startOfDay();
                    $now->addDay();
                    $now->addSecond($this->worker->loop_value);
                    $scope = $now->getTimestamp() * 1000;
                    break;
                case 13:
                    $now->addHour(1);
                    $now->minute = 0;
                    $now->second = 0;
                    $now->addSecond($this->worker->loop_value);
                    $scope = $now->getTimestamp() * 1000;
                    break;
                default:
                    $scope = max(millisecond() + 3000, $this->worker->begin * 1000 + 2000);
            }
        }
        $this->setRunTag($scope);
        $this->worker->run_tag = $scope;
        if($autoSave){
            $this->worker->save();
        }
    }

    public function modify($params)
    {
        if(!$this->tasker){
            $task = $this->worker->task_id;
            $this->tasker = new $task();
            if(!($this->tasker instanceof AbsTasker)){
                throw new JsonErrorException("非法任务处理器");
            }
        }
        $this->tasker->setData($params['param']);
        $this->tasker->checkRule();
        $begin	= Carbon::parse($params['begin'])->getTimestamp();
        $end	= Carbon::parse($params['end'])->getTimestamp();
        $need	= false;
        if($this->worker->loop_type != $params['loop_type']
            || $this->worker->loop_value!= $params['loop_value']
            || $this->worker->max_count!= $params['max_count']
            || $this->worker->begin != $begin || $this->worker->end != $end){
            $need = true;
        }
        $this->worker->mode = $params['mode'];
        $this->worker->loop_value = $params['loop_value'];
        $this->worker->loop_type = $params['loop_type'];
        if($this->worker->max_count != $params['max_count']){
        	$this->worker->use_count = 0;//重置该值
        }
        $this->worker->max_count = $params['max_count'];
        $this->worker->begin = $begin;
        $this->worker->end = $end;
        $this->worker->name = $params['name'];
        $this->worker->updated_at = time();
        $this->worker->param = $params['param'];
        $this->worker->status = $params['status'];
        if($need){
            $this->resetRunTag(false);
        }
        $this->worker->save();
    }

    public static function logWorker($workerId, $runStatus, $msg = '')
    {
        /**
         * @var $cache TaskWorkerLog
         */
        $cache = Cache::moduleStore('taskWorkerLog');
        $cache->addLog($workerId, [
            'worker_id' => $workerId,
            'created_at' => millisecond(),
            'run_status' => $runStatus,
            'remark' => $msg
        ]);
    }

    public function __set($key, $val)
    {
        $this->worker->$key = $val;
    }

    public function __get($key)
    {
        return $this->worker->$key;
    }

    public function __call($method, $params)
    {
        call_user_func_array([$this->worker, $method], $params);
    }
}