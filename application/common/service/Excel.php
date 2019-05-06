<?php
namespace app\common\service;

use app\common\exception\JsonErrorException;
use think\Loader;
use think\Exception;
Loader::import('phpExcel.PHPExcel', VENDOR_PATH);
Loader::import('XLSXWriter.xlsxwriter','','.class.php');

/**
 * class Excel
 * @package app\common\service
 */

class Excel
{
    const DEFAULT_FILE_EXT = 'xlsx';
    static $sheet_name = 'Sheet1';
    public static function foo($s)
    {
        $s = strtoupper($s);
        $res = 0;
        for($i=0; $i<strlen($s); $i++) {
            $res = 26 * $res + ord($s{$i});
        }
        return $res;
    }
    /**
     * 按列读取excel
     * @param string $excelPath excel路径
     * @param string $allColumn 读取的列数
     * @param string $sheet 读取的工作表
     * @param string $type  字段类型(指定字段为时间格式)
     * @return $data
     */
    public static function readExcel($excelPath, $allColumn = 0, $sheet = 0, $type = '')
    {
        ini_set('memory_limit', '2048M');
        $data = [];
        try {
            $pathInfo = pathInfo($excelPath);
            $extension = strtolower($pathInfo['extension']);
             // 返回 mime 类型
            if(in_array($extension,['xlsx','xls'])){
                $finfo = finfo_open(FILEINFO_MIME);
                $fileType = finfo_file($finfo, $excelPath);
                finfo_close($finfo);
                $cutpos = strpos($fileType,';');
                if($cutpos){
                    $fileType = substr($fileType,0,$cutpos);
                }
                if($fileType=='application/vnd.ms-office'){
                    $extension = 'xls';
                }else if('application/zip'==$fileType){
                    $extension = 'xlsx';
                }else if('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' == $fileType){
                    $extension = 'xlsx';
                }else if('application/octet-stream' == $fileType){
                    $extension = 'xlsx';
                }
            }
            switch ($extension) {
                case 'xlsx':
                    $phpReader = \PHPExcel_IOFactory::createReader('Excel2007');
                    break;
                case 'xls':
                    $phpReader = \PHPExcel_IOFactory::createReader('Excel5');
                    break;
                case 'csv':
                    $phpReader = \PHPExcel_IOFactory::createReader('CSV');
                    break;
                default:
                    throw new Exception('文件格式不为 xlsx、xls、csv');
            }
        } catch(\Exception $e) {
            throw new Exception($e->getMessage());
        }
        //载入excel文件
        $phpExcel  = $phpReader->load($excelPath);
        //获取工作表总数
        $sheetCount = $phpExcel->getSheetCount();
        //判断是否超过工作表总数，取最小值
        $sheet = $sheet < $sheetCount ? $sheet : $sheetCount;
        //默认读取excel文件中的第一个工作表
        $currentSheet = $phpExcel->getSheet($sheet);
        if(empty($allColumn)) {
            //取得最大列号，这里输出的是大写的英文字母，ord()函数将字符转为十进制，65代表A
            //$allColumn = ord($currentSheet->getHighestColumn()) - 65 + 1;
            $allColumn = ord($currentSheet->getHighestColumn()) - 0 + 1;
        }
        //取得一共多少行
        $allRow = $currentSheet->getHighestRow();
    
        //从第二行开始输出，因为excel表中第一行为列名
        for($currentRow = 2; $currentRow <= $allRow; $currentRow++) {
            for($currentColumn = 0; $currentColumn <= $allColumn - 1; $currentColumn++) {
                $val = trim($currentSheet->getCellByColumnAndRow($currentColumn, $currentRow)->getValue());
                $title = trim($currentSheet->getCellByColumnAndRow($currentColumn, 1)->getValue());
                if ($type) {
                    if (is_array($type)) {
                        foreach ($type as $v) {
                            if ($title[$currentColumn] == $v) {
                                $val=self::excelTime($val);
                            }
                        }
                    } else {
                        if ($title[$currentColumn] == $type) {
                            $val = self::excelTime($val);
                        }
                    }
                }
                if(is_object($val)) {
                    $val= $val->__toString();
                }
                
                $data[$currentRow - 2][$title] =  $val;
            }
        }
        
        return $data;
    }
    
    /**
     * 将 Excel 时间转为标准的时间格式
     * @param $date
     * @param bool $time
     * @return array|int|string
     */
    private static function excelTime($date, $time = false)
    {
        if (function_exists('GregorianToJD')) {
            if (is_numeric( $date )) {
                $jd = GregorianToJD( 1, 1, 1970 );
                $gregorian = JDToGregorian( $jd + intval ( $date ) - 25569 );
                $date = explode( '/', $gregorian );
                $date_str = str_pad( $date [2], 4, '0', STR_PAD_LEFT )
                ."/". str_pad( $date [0], 2, '0', STR_PAD_LEFT )
                ."/". str_pad( $date [1], 2, '0', STR_PAD_LEFT )
                . ($time ? " 00:00:00" : '');
                return $date_str;
            }
        } else {
            $date= $date >25568 ? $date+1 : 25569;
            /*There was a bug if Converting date before 1-1-1970 (tstamp 0)*/
            $ofs = (70 * 365 + 17+2) * 86400;
            $date = date("Y-m-d",($date * 86400) - $ofs).($time ? " 00:00:00" : '');
        }
        return $date;
    }
    public static function createRanger($length,&$return=[]){
        $initRange = range('A','Z',1);
        if(count($initRange)>=$length){
            return $initRange;
        }else{
            $return = array_merge($return,$initRange);
            $left = $length - count($return);
            $startWord = array_pop($return);
            $startNum=0;
            for ($i = $startWord; $i <= 'ZZ'; $i++){
                array_push($return,$i);
                if($startNum<$left){
                    ++$startNum;
                }else{
                    break;
                }
            }
            return $return;
        }
    }

    private static function arrayGroupBy($arr, $key)
    {
        $grouped = [];
        foreach ($arr as $value) {
            $grouped[$value[$key]][] = $value;
        }
        // Recursively build a nested grouping if more parameters are supplied
        // Each grouped array value is grouped according to the next sequential key
        if (func_num_args() > 2) {
            $args = func_get_args();
            foreach ($grouped as $key => $value) {
                $parms = array_merge([$value], array_slice($args, 2, func_num_args()));
                $grouped[$key] = call_user_func_array('array_group_by', $parms);
            }
        }
        return $grouped;
    }

    /**
     * 导出封装
     * @param $header
     * @param $lists
     * @param $file
     * @return array
     * @throws \PHPExcel_Exception
     * @throws \PHPExcel_Reader_Exception
     * @throws \PHPExcel_Writer_Exception
     */
    public static function exportExcel2007($header, $lists, $file, $isQueue = 0)
    {
        opcache_reset();
        set_time_limit(0);
        $fileName = $file['name'];
        $pathCode = $file['path'];
        $downFileName = $fileName . '.xlsx';
        $file = ROOT_PATH . 'public' . DS . 'download' . DS . $file['path'];
        $filePath = $file . DS . $downFileName;
        //无文件夹，创建文件夹
        if (!is_dir($file) && !mkdir($file, 0777, true)) {
            $result['message'] = '创建文件夹失败。';
            @unlink($filePath);
            return $result;
        }
        $fullName = $filePath;
        //创建excel对象
        $writer = new \XLSXWriter();
        foreach ($header as $t => $tt) {
            $title[$tt['title']] = 'string';
            if (isset($tt['need_merge']) && $tt['need_merge'] == 1){
                $needMerge[$tt['key']] = $t;//需要合并的字段key
            }
            if (isset($tt['key'])){
                $fieldKeys[] = $tt['key'];
            }
            if (isset($tt['main_need_merge_key']) && $tt['main_need_merge_key'] == 1){
                //如果存在合并主key，使用主key合并行
                $mainNeedMergerKey = $tt['key'];
            }
            $colWidths[] = (isset($tt['width']))?$tt['width']:20;//宽度
        }
        if(isset($lists[0]['id']) && !in_array('id', $fieldKeys)){
            $fieldKeys[] = 'id';
        }

        $mainNeedMergerKey = isset($mainNeedMergerKey)?$mainNeedMergerKey:'id';
        $countCol = count($title);//写入标题列数
        $writer->writeSheetHeader(self::$sheet_name, $title, ['font-style'=>'bold', 'widths'=>$colWidths]);
        if (in_array($mainNeedMergerKey,$fieldKeys) && !empty($needMerge)){
            $lists = self::arrayGroupBy($lists,$mainNeedMergerKey);
            foreach ($lists as $list){
                $startRow = $writer->countSheetRows();//开始行数
                foreach ($list as $key=>$item){
                    $row = [];
                    //只取标题key值
                    foreach ($fieldKeys as $keyName){
                        $item[$keyName] = (is_null($item[$keyName]))?'':$item[$keyName];
                        $row[$keyName] = (isset($item[$keyName]))?$item[$keyName]:'';
                        if (count($row) == $countCol){
                            break;//写入列达到标题最大列，多余数据不取
                        }
                    }
                    $writer->writeSheetRow(self::$sheet_name, $row, ['valign'=>'center']);
                }
                $endRow = $writer->countSheetRows() - 1;//结束行数
                //合并行数
                foreach ($needMerge as $key){
                    $writer->markMergedCell(self::$sheet_name,$startRow, $key, $endRow,$key);
                }
                //超过一百万行写入下一页
                if ($endRow > 1000000){
                    $num = substr(self::$sheet_name,-1);
                    if (is_numeric($num)){
                        $num += 1;
                    }
                    self::$sheet_name = 'Sheet'.$num;
                    $writer->writeSheetHeader(self::$sheet_name, $title, ['font-style'=>'bold', 'widths'=>$colWidths]);
                }
            }
        }else{
            foreach ($lists as $list){
                $row = [];
                //只取标题key值
                foreach ($fieldKeys as $keyName){
                    $list[$keyName] = (is_null($list[$keyName]))?'':$list[$keyName];
                    if (isset($list[$keyName])){
                        $row[$keyName] = $list[$keyName];
                    }
                    if (count($row) == $countCol){
                        break;//写入列达到标题最大列，多余数据不取
                    }
                }
                $writer->writeSheetRow(self::$sheet_name, $row, ['valign'=>'center']);
                $endRow = $writer->countSheetRows() - 1;//结束行数
                //超过一百万行写入下一页
                if ($endRow > 1000000){
                    $num = substr(self::$sheet_name,-1);
                    if (is_numeric($num)){
                        $num += 1;
                    }
                    self::$sheet_name = 'Sheet'.$num;
                    $writer->writeSheetHeader(self::$sheet_name, $title, ['font-style'=>'bold', 'widths'=>$colWidths]);
                }
            }
        }
        $writer->writeToFile($fullName);
        //记录日志
        $logExportDownloadFiles = new \app\common\model\LogExportDownloadFiles();
        try {
            $data = [];
            $data['file_extionsion'] = 'xlsx';
            $data['saved_path'] = $filePath;
            $data['download_file_name'] = $downFileName;
            $data['type'] = 'supplier_export';
            $data['created_time'] = time();
            $data['updated_time'] = time();
            $logExportDownloadFiles->allowField(true)->isUpdate(false)->save($data);
            $udata = [];
            $udata['id'] = $logExportDownloadFiles->id;
            $udata['file_code'] = date('YmdHis') . $logExportDownloadFiles->id;
            $logExportDownloadFiles->allowField(true)->isUpdate(true)->save($udata);
        } catch (\Exception $e) {
            $result['message'] = '创建导出文件日志失败。' . $e->getMessage();
            @unlink($filePath);
            return $result;
        }

        $result['status'] = 1;
        $result['message'] = '导出成功';
        $result['file_code'] = $udata['file_code'];
        $result['file_name'] = $fileName;
        if($isQueue){
            $result['file_path'] = $filePath;
            $result['download_url']  =  DS .'download'. DS . $pathCode . DS .$downFileName;
        }
        return $result;

        //创建excel对象
        /*$excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $titleRowIndex = 1;
        $dataRowStartIndex = 2;
        $dataMap   = $header;

        //$letter = range('A', 'Z');
        $letter = self::createRanger(count($header));

        $result=['status'=>0];
        //设置表头和表头样式
        foreach ($dataMap as $col => $set) {
            $sheet->getColumnDimension($letter[$col])->setWidth($set['width']);
            $sheet->getCell($letter[$col] . $titleRowIndex)->setValue($set['title']);
            $sheet->getStyle($letter[$col] . $titleRowIndex)
                ->getFill()
                ->setFillType(\PHPExcel_Style_Fill::FILL_SOLID);
            $sheet->getStyle($letter[$col] . $titleRowIndex)
                ->getBorders()
                ->getAllBorders()
                ->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
            $sheet->getStyle($letter[$col] . $titleRowIndex)->getFont()->setSize(12)->setBold(true); //字体加粗

        }
        foreach ($lists as $key=>$r){
            foreach ($dataMap as $field => $set){
                if (is_numeric($r[$set['key']])) {
                    if($pos = strpos($r[$set['key']], '.')){
                        $str = substr($r[$set['key']], 0, $pos);
                    }else{
                        $str = $r[$set['key']];
                    }
                    if(strlen($str) >= 11) $r[$set['key']] = ' ' . $r[$set['key']];//避免科学计数法
                }
                $cell = $sheet->getCell($letter[$field]. $dataRowStartIndex);
                $sheet->getStyle($letter[$field] . $dataRowStartIndex)
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
                if($set['need_merge'] && ($dataRowStartIndex>2 && $r['id']==$lists[$key-1]['id'])){
                    if(isset($lists[$key+1]['id']) && $lists[$key+1]['id']==$r['id']){
                        $cell->setValue($r[$set['key']]);
                    }else{
                        $first_key = self::getFirstCells($key, $lists);
                        $first_index =  $dataRowStartIndex-($key-$first_key);
                        $excel->getActiveSheet()->mergeCells($letter[$field].$first_index.':'.$letter[$field].$dataRowStartIndex);
                        $excel->getActiveSheet()->getStyle($letter[$field].$first_index)->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);
                        $sheet->setCellValue($letter[$field].$first_index, $r[$set['key']]);
                    }
                }else {
                    if($set['key'] == 'thumb' && is_string($r[$set['key']]) && $r[$set['key']]){
                        if(preg_match("/.*\.png$/", $r[$set['key']])){
                            $img = @imagecreatefrompng($r[$set['key']]);
                        }else if(preg_match("/.*\.(jpg|jpeg)$/", $r[$set['key']])){
                            $img = @imagecreatefromjpeg($r[$set['key']]);
                        }else if(preg_match("/.*\.gif$/", $r[$set['key']])){
                            $img = @imagecreatefromgif($r[$set['key']]);
                        }else{
                            $cell->setValue($r[$set['key']]);
                            continue;
                        }
                        if(! $img){
                            $cell->setValue($r[$set['key']]);
                            continue;
                        }
                        $sheet->getRowDimension($dataRowStartIndex)->setRowHeight(50);
                        $objDrawing = new \PHPExcel_Worksheet_MemoryDrawing();
                        $objDrawing->setCoordinates($letter[$field]. $dataRowStartIndex);
                        $objDrawing->setImageResource($img);
                        $objDrawing->setOffsetX(10);
                        $objDrawing->setOffsetY(5);
                        $objDrawing->setRenderingFunction(\PHPExcel_Worksheet_MemoryDrawing::RENDERING_DEFAULT);//渲染方法
                        $objDrawing->setMimeType(\PHPExcel_Worksheet_MemoryDrawing::MIMETYPE_DEFAULT);
                        $objDrawing->setHeight(60);
                        $objDrawing->setWidth(60);
                        $objDrawing->setWorksheet($sheet);
                    }else{
                        $cell->setValue($r[$set['key']]);
                    }
                }

            }

            $dataRowStartIndex++;
        }

        $fileName = $file['name'];
        $pathCode = $file['path'];
        $downFileName = $fileName . '.xlsx';
        $file = ROOT_PATH . 'public' . DS . 'download' . DS . $file['path'];
        $filePath = $file . DS . $downFileName;
        //无文件夹，创建文件夹
        if (!is_dir($file) && !mkdir($file, 0777, true)) {
            $result['message'] = '创建文件夹失败。';
            @unlink($filePath);
            return $result;
        }
        $objWriter = \PHPExcel_IOFactory::createWriter($excel,'Excel2007');
        $objWriter->save($filePath);
        $logExportDownloadFiles = new \app\common\model\LogExportDownloadFiles();
        try {
            $data = [];
            $data['file_extionsion'] = 'xlsx';
            $data['saved_path'] = $filePath;
            $data['download_file_name'] = $downFileName;
            $data['type'] = 'supplier_export';
            $data['created_time'] = time();
            $data['updated_time'] = time();
            $logExportDownloadFiles->allowField(true)->isUpdate(false)->save($data);
            $udata = [];
            $udata['id'] = $logExportDownloadFiles->id;
            $udata['file_code'] = date('YmdHis') . $logExportDownloadFiles->id;
            $logExportDownloadFiles->allowField(true)->isUpdate(true)->save($udata);
        } catch (\Exception $e) {
            $result['message'] = '创建导出文件日志失败。' . $e->getMessage();
            @unlink($filePath);
            return $result;
        }

        $result['status'] = 1;
        $result['message'] = '导出成功';
        $result['file_code'] = $udata['file_code'];
        $result['file_name'] = $fileName;
        if($isQueue){
            $result['file_path'] = $filePath;
            $result['download_url']  =  DS .'download'. DS . $pathCode . DS .$downFileName;
        }
        return $result;*/
    }

    /**
     * 递归获取第一个
     */
    private static function getFirstCells($key, $data){
        if(isset($data[$key-1]) && $data[$key]['id']==$data[$key-1]['id']){
            return self::getFirstCells($key-1, $data);
        }else {
            return $key;
        }
    }

    /**
     * @desc 读取excel数据
     * @param $inputFileName
     * @param $header_mapping
     * @return array
     * @throws Exception
     * @author Reece
     * @date 2018-06-16 18:01:06
     */
    public static function getExcelData($inputFileName, $header_mapping)
    {

        $primary =  reset($header_mapping)['field'];

        $inputFileType = \PHPExcel_IOFactory::identify($inputFileName);
        $objReader = \PHPExcel_IOFactory::createReader($inputFileType);
        $objReader->setReadDataOnly(true);//读取优化

        $ex = $objReader->load($inputFileName);
        $cur = $ex->getSheet(0);  // 读取第一个表
        $highestColumn = $cur->getHighestColumn(); // 获得最大的列数
        $highestColumnNum = \PHPExcel_Cell::columnIndexFromString($highestColumn);
        $highestRowNum = $cur->getHighestRow(); // 获得最大总行数
        $usefullColumnNum = $highestColumnNum;
        $filed = [];
        for($i=0; $i<$highestColumnNum;$i++){
            $cellName = \PHPExcel_Cell::stringFromColumnIndex($i).'1';
            $cellVal = $cur->getCell($cellName)->getValue();//取得列内容
            if( !$cellVal ){
                break;
            }
            $usefullColumnNum = $i;
            $filed []= $cellVal;
        }

        //开始取出数据并存入数组
        $data = [];
        for( $i=2; $i <= $highestRowNum ;$i++ ){//ignore row 1
            $row = [];
            $detail = [];
            for( $j = 0; $j <= $usefullColumnNum;$j++ ){
                if( !isset($header_mapping[ $filed[$j] ]) ){
                    continue;
                }
                $cellName = \PHPExcel_Cell::stringFromColumnIndex($j).$i;
                $cellVal = $cur->getCell($cellName)->getValue();
                if($cellVal instanceof \PHPExcel_RichText){ //富文本转换字符串
                    $cellVal = $cellVal->__toString();
                }
                if($header_mapping[ $filed[$j] ]['require'] && $cellVal == ''){
                    throw new Exception("第{$i}行{$filed[$j]}不能为空");
                }
                $fd = $header_mapping[ $filed[$j] ]['field'];
                if($header_mapping[ $filed[$j] ]['merge']){
                    $detail[$fd] = $cellVal;
                }
                $row[ $fd ] = $cellVal;
            }
            if($detail){
                if(isset($data[$row[$primary]])){
                    array_push($data[$row[$primary]]['detail'], $detail);
                }else{
                    foreach($detail as $k=>$v){
                        unset($row[$k]);
                    }
                    $data[$row[$primary]] = array_merge($row,['detail' => [$detail]]);
                }
            }else{
                $data[$row[$primary]] = $row;
            }
        }
        return $data;
    }

    public static function getTongtuFieldMap()
    {
        $header_mapping = [
            '采购单号' => ['field'=>'purchase_order_code', 'require' => 1, 'merge'=>0],
            '采购计划编号' => ['field'=>'purchase_plan_number', 'require' => 0, 'merge'=>0],
            '跟踪号' => ['field'=>'tracking_number', 'require' => 0, 'merge'=>0],
            '运输方式' => ['field'=>'shipping_type', 'require' => 0, 'merge'=>0],
            '外部流水号' => ['field'=>'external_number', 'require' => 0, 'merge'=>0],
            '采购仓库' => ['field'=>'warehouse_id', 'require' => 1, 'merge'=>0],
            '采购员' => ['field'=>'purchase_user_id', 'require' => 1, 'merge'=>0],
            '采购日期' => ['field'=>'purchase_time', 'require' => 1, 'merge'=>0],
            //'货位' => '',
            'SKU' => ['field'=>'sku', 'require' => 1, 'merge'=>1],
            //'SKU别名' => '',
            //'货品名称' => '',
            //'规格' => '',
            '采购链接' => ['field'=>'link', 'require' => 0, 'merge'=>1],
            //'产品特点' => '',
            '采购币种' => ['field'=>'currency_code', 'require' => 0, 'merge'=>0],
            '采购单价' => ['field'=>'price', 'require' => 1, 'merge'=>1],
            //'最新报价' => '',
            '采购数量' => ['field'=>'qty', 'require' => 1, 'merge'=>1],
            '实际到货数量' => ['field'=>'expected_int_qty', 'require' => 0, 'merge'=>1],
            '实际入库数量' => ['field'=>'in_qty', 'require' => 0, 'merge'=>1],
            '实际应付货款' => ['field'=>'amount', 'require' => 1, 'merge'=>0],
            '运费' => ['field'=>'shipping_cost', 'require' => 0, 'merge'=>0],
            //'供应商代码' => '',
            '供应商名称' => ['field'=>'supplier_id', 'require' => 1, 'merge'=>0],
            //'结算方式' => 'supplier_balance_type',
            //'联系人' => '',
            //'联系电话' => '',
            //'传真号' => '',
            //'QQ号' => '',
            //'阿里旺旺' => '',
            //'Email' => '',
            //'国家/地区' => '',
            //'省/州' => '',
            //'城市' => '',
            //'详细地址' => '',
            '创建人' => ['field'=>'create_user_id', 'require' => 1, 'merge'=>0],
            '备注' => ['field'=>'remark', 'require' => 0, 'merge'=>0],
            '预计到达日期' => ['field'=>'expect_arrive_date', 'require' => 0, 'merge'=>0],
            '到货日期' => ['field'=>'real_arrive_date', 'require' => 0, 'merge'=>0],
            '付款状态' => ['field'=>'payment_status', 'require' => 1, 'merge'=>0],
            '采购状态'  => ['field'=>'status', 'require' => 1, 'merge'=>0],
        ];
        return $header_mapping;
    }

    public static function exportFinancePurchaseTmp($data, $file, $isQueue = 0)
    {
        $excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->getDefaultColumnDimension()->setWidth(25);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1:D1')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A1:D1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A1')->setValue('有棵树电子商务有限公司');

        $sheet->mergeCells('A2:D2');
        $sheet->getStyle('A2:D2')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A2:D2')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A2')->setValue('订购单');

        $sheet->mergeCells('A3:D3');
        $sheet->getCell('A3')->setValue('供应商:'.$data['company_name']);

        $sheet->mergeCells('A4:D4');
        $sheet->getCell('A4')->setValue('订购日期:');

        $sheet->mergeCells('A5:D5');
        $sheet->getCell('A5')->setValue('币种:人民币(CNY)');

        $sheet->getStyle('A6:D6')->getFont()->setBold(true);

        $tableEndRow = 6+count($data['table_data'])+1;
        $sheet->getStyle('A6:D'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A6:D'.$tableEndRow)->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $header = ['A'=>'品名', 'B'=>'数量', 'C'=>'单价', 'D'=>'金额'];
        foreach($header as $k=>$v){
            $sheet->getCell($k.'6')->setValue($v);
        }

        $map = ['A'=>'goods_name', 'B'=>'sum_qty', 'C'=>'single_price', 'D'=>'sum_price'];
        foreach($data['table_data'] as $k=>$v){
            $rowNum = $k+7;
            for($colNum='A';$colNum<='D';$colNum++){
                $sheet->getCell($colNum.$rowNum)->setValue($v[$map[$colNum]]);
            }
        }
        $sheet->getCell('C'.$tableEndRow)->setValue('小计:');
        $sheet->getCell('D'.$tableEndRow)->setValue($data['amount']);

        $supplierRow = $tableEndRow+2;
        $sheet->getCell('A'.$supplierRow)->setValue('收款人信息:');
        /*foreach($data['supplier_info'] as $k=>$v){
            $sheet->getCell('A'.$supplierRow++)->setValue(str_replace('_', ' ', $k).':'.$v);
        }*/
        $sheet->getCell('A'.($supplierRow+1))->setValue('收款人: ' . $data['supplier_info']['account_name']);
        $sheet->getCell('A'.($supplierRow+2))->setValue('卡号: ' . $data['supplier_info']['bank_account']);
        $sheet->getCell('A'.($supplierRow+3))->setValue('银行: ' . $data['supplier_info']['bank']);
        //$footerRow = $supplierRow+2;
        $footerRow = $supplierRow+5;
        $sheet->getCell('A'.$footerRow)->setValue('供方:'.$data['company_name']);
        $sheet->getCell('D'.$footerRow)->setValue('需方:有棵树电子商务有限公司');

        $footerDateRow = $footerRow+1;
        $sheet->getCell('A'.$footerDateRow)->setValue('日期:');
        $sheet->getCell('D'.$footerDateRow)->setValue('日期:');
        $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $savePath = self::makeSavePath($file);
        $objWriter->save($savePath);

        $fileCode = self::addLogExportDownloadFiles($file, $savePath);
        $result = [
            'status' => 1,
            'message' => 'OK',
            'file_code' => $fileCode,
            'file_name' => $file['name']
        ];
        if($isQueue){
            $result['file_path'] = $savePath;
            $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $result['download_url']  =  DS.'download'.DS.$file['path'].DS.$file['name'].'.'.$fileExt;
        }
        return $result;
    }

    private static function addLogExportDownloadFiles($file, $savePath)
    {
        try {
            $logExportDownloadFiles = new \app\common\model\LogExportDownloadFiles();
            $data = [];
            $data['file_extionsion'] = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $data['saved_path'] = $savePath;
            $data['download_file_name'] = $file['name'].'.'.$data['file_extionsion'];
            $data['type'] = 'supplier_export';
            $data['created_time'] = time();
            $data['updated_time'] = time();
            $logExportDownloadFiles->allowField(true)->isUpdate(false)->save($data);
            $udata = [];
            $udata['id'] = $logExportDownloadFiles->id;
            $udata['file_code'] = date('YmdHis') . $logExportDownloadFiles->id;
            $logExportDownloadFiles->allowField(true)->isUpdate(true)->save($udata);
            return $udata['file_code'];
        } catch (Exception $e) {
            @unlink($savePath);
            throw new JsonErrorException('创建导出文件日志失败。' . $e->getMessage());
        }
    }

    private static function makeSavePath($file)
    {
        $fileName = $file['name'];
        $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
        $downFileName = $fileName .'.'. $fileExt;
        $file = ROOT_PATH . 'public' . DS . 'download' . DS . $file['path'];
        $filePath = $file . DS . $downFileName;
        //无文件夹，创建文件夹
        if (!is_dir($file) && !mkdir($file, 0777, true)) {
            throw new JsonErrorException('创建文件夹失败');
        }
        return $filePath;
    }

    public static function exportFinanceReceiptTmp($data, $file, $isQueue = 0)
    {
        $excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->getDefaultColumnDimension()->setWidth(25);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1:F1')->getFont()->setSize(16);
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A1')->setValue('收货单');

        $sheet->mergeCells('A2:F2');
        $sheet->getStyle('A2:F2')->getFont()->setSize(16);
        $sheet->getStyle('A2:F2')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A2')->setValue($data['receipt_company_name']);

        $sheet->mergeCells('B3:D3');
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A3')->setValue('供应商:');
        $sheet->getCell('B3')->setValue($data['company_name']);

        $tableEndRow = 4+count($data['table_data'])+1;
        $sheet->getStyle('A4:F'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A4:F'.$tableEndRow)->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $header = ['A'=>'收货单号', 'B'=>'收货人', 'C'=>'品名', 'D'=>'数量', 'E'=>'收货时间', 'F'=>'备注'];
        foreach($header as $k=>$v){
            $sheet->getCell($k.'4')->setValue($v);
        }

        $map = ['A'=>'receipt_code', 'B'=>'receipt_name', 'C'=>'goods_name', 'D'=>'sum_qty'];
        foreach($data['table_data'] as $k=>$v){
            $rowNum = $k+5;
            for($colNum='A';$colNum<='D';$colNum++){
                $v['receipt_code'] = $data['receipt_code'];
                $sheet->getCell($colNum.$rowNum)->setValue($v[$map[$colNum]]);
            }
        }
        $sheet->getStyle("A$tableEndRow:D$tableEndRow")->getFont()->setBold(true);
        $sheet->getCell('A'.$tableEndRow)->setValue('总计');
        $sheet->getCell('D'.$tableEndRow)->setValue($data['amount']);

        $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $savePath = self::makeSavePath($file);
        $objWriter->save($savePath);

        $fileCode = self::addLogExportDownloadFiles($file, $savePath);
        $result = [
            'status' => 1,
            'message' => 'OK',
            'file_code' => $fileCode,
            'file_name' => $file['name']
        ];
        if($isQueue){
            $result['file_path'] = $savePath;
            $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $result['download_url']  =  DS.'download'.DS.$file['path'].DS.$file['name'].'.'.$fileExt;
        }
        return $result;
    }

    public static function exportFinanceInStockTmp($data, $file, $isQueue = 0)
    {
        $excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->getDefaultColumnDimension()->setWidth(15);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1:I1')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A1:I1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A1')->setValue('入库单');

        $sheet->mergeCells('A2:I2');
        $sheet->getStyle('A2:I2')->getFont()->setSize(16)->setBold(true);
        $sheet->getStyle('A2:I2')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A2')->setValue($data['in_stock_company_name']);

        $sheet->mergeCells('B3:I3');
        $sheet->getStyle('A3:I3')->getFont()->setSize(16);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A3')->setValue('供货单位:');
        $sheet->getCell('B3')->setValue($data['company_name']);

        $tableEndRow = 4+count($data['table_data'])+1;
        $sheet->getStyle('A4:I'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A4:I'.$tableEndRow)->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $header = ['A'=>'入库单号', 'B'=>'收货操作人', 'C'=>'品名', 'D'=>'入库数量', 'E'=>'币别', 'F'=>'入库单价', 'G'=>'入库总金额', 'H'=>'入库时间', 'I'=>'备注'];
        foreach($header as $k=>$v){
            $sheet->getCell($k.'4')->setValue($v);
        }

        $map = ['A'=>'in_stock_code', 'B'=>'in_stock_name', 'C'=>'goods_name', 'D'=>'sum_qty', 'E'=>'currency_code', 'F'=>'single_price', 'G'=>'sum_price'];
        foreach($data['table_data'] as $k=>$v){
            $rowNum = $k+5;
            for($colNum='A';$colNum<='G';$colNum++){
                $v['in_stock_code'] = $data['in_stock_code'];
                $sheet->getCell($colNum.$rowNum)->setValue($v[$map[$colNum]]);
            }
        }
        $sheet->getCell('A'.$tableEndRow)->setValue('合计');
        $sheet->getCell('D'.$tableEndRow)->setValue($data['amount_qty']);
        $sheet->getCell('G'.$tableEndRow)->setValue($data['amount_price']);

        $footerRow = $tableEndRow+1;
        $sheet->mergeCells("A$footerRow:B$footerRow");
        $sheet->mergeCells("C$footerRow:D$footerRow");
        $sheet->getStyle("A$footerRow:D$footerRow")->getFont()->setSize(16)->setBold(true);
        $sheet->getCell('A'.$footerRow)->setValue('负责人:');
        $sheet->getCell('C'.$footerRow)->setValue('财务人员:');

        $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $savePath = self::makeSavePath($file);
        $objWriter->save($savePath);

        $fileCode = self::addLogExportDownloadFiles($file, $savePath);
        $result = [
            'status' => 1,
            'message' => 'OK',
            'file_code' => $fileCode,
            'file_name' => $file['name']
        ];
        if($isQueue){
            $result['file_path'] = $savePath;
            $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $result['download_url']  =  DS.'download'.DS.$file['path'].DS.$file['name'].'.'.$fileExt;
        }
        return $result;
    }

    public static function exportFinanceDeliverTmp(array $data, array $file, int $isQueue = 0): array
    {
        $excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->getDefaultColumnDimension()->setWidth(40);
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        // 第一行 公司名称
        $sheet->mergeCells('A1:E2');
        $sheet->getStyle('A1:E2')->getFont()->setSize(16);
        $sheet->getStyle('A1:E2')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A1')->setValue($data['company']);
        // 第二行 送货单
        $sheet->mergeCells('A3:E3');
        $sheet->getStyle('A3:E3')->getFont()->setSize(14);
        $sheet->getStyle('A3:E3')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A3')->setValue('送货单');
        // 第三行 送货日期和单据编号
        $sheet->getStyle('A4')->getFont()->setSize(14);
        $sheet->getCell('A4')->setValue('送货日期');
        $sheet->mergeCells('C4:E4');
        $sheet->getStyle('C4:E4')->getFont()->setSize(14);
        $sheet->getStyle('C4:E4')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('C4')->setValue('单据编号: ' . $data['deliver_code']);
        // 第四行 客户单位和联系方式
        $sheet->getStyle('A5')->getFont()->setSize(14);
        $sheet->getCell('A5')->setValue('客户单位');
        $sheet->getStyle('B5')->getFont()->setSize(14);
        $sheet->getCell('B5')->setValue($data['customer']);
        $sheet->mergeCells('C5:E5');
        $sheet->getStyle('C5:E5')->getFont()->setSize(14);
        $sheet->getStyle('C5:E5')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('C5')->setValue($data['contact']);
        // 第五行 收货单位
        $sheet->getStyle('A6')->getFont()->setSize(14);
        $sheet->getCell('A6')->setValue('收货单位');
        $sheet->mergeCells('B6:E6');
        $sheet->getStyle('B6:E6')->getFont()->setSize(14);
        $sheet->getCell('B6')->setValue($data['reception']);
        // 第六行 收货地址
        $sheet->getStyle('A7')->getFont()->setSize(14);
        $sheet->getCell('A7')->setValue('收货地址');
        $sheet->mergeCells('B7:E7');
        $sheet->getStyle('B7:E7')->getFont()->setSize(14);
        $sheet->getCell('B7')->setValue($data['reception_address']);
        // 第八行和第九行
        $tableEndRow = 8+count($data['table_data'])+1;
        $sheet->getStyle('A8:E'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A8:E'.$tableEndRow)->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $sheet->getStyle('A1:E'.$tableEndRow)->getFont()->setName('宋体');
        $sheet->getStyle('A8:E'.$tableEndRow)->getFont()->setSize(12);
        $header = ['A'=>'序号', 'B'=>'订单号', 'C'=>'品名', 'D'=>'数量', 'E'=>'备注'];
        foreach($header as $k=>$v){
            $sheet->getCell($k.'8')->setValue($v);
        }

        $map = ['B'=>'virtual_purchase_order_id', 'C'=>'goods_name', 'D'=>'qty'];
        $number = 1;
        foreach($data['table_data'] as $k=>$v) {
            $rowNum = $k + 9;
            for($colNum='B';$colNum<='D';$colNum++) {
                $sheet->getCell('A' . $rowNum)->setValue($number);
                $sheet->getCell($colNum.$rowNum)->setValue($v[$map[$colNum]]);
            }
            $number++;
        }
        $sheet->getStyle("A$tableEndRow:D$tableEndRow")->getFont()->setBold(true);
        $sheet->mergeCells('A'.$tableEndRow . ':C'.$tableEndRow);
        $sheet->getStyle('A'.$tableEndRow . ':C'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A'.$tableEndRow)->setValue('总计');
        $sheet->getCell('D'.$tableEndRow)->setValue($data['total_qty']);

        $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $savePath = self::makeSavePath($file);
        $objWriter->save($savePath);

        $fileCode = self::addLogExportDownloadFiles($file, $savePath);
        $result = [
            'status' => 1,
            'message' => 'OK',
            'file_code' => $fileCode,
            'file_name' => $file['name']
        ];
        if($isQueue){
            $result['file_path'] = $savePath;
            $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $result['download_url']  =  DS.'download'.DS.$file['path'].DS.$file['name'].'.'.$fileExt;
        }
        return $result;
    }

    public static function exportFinanceInvoiceTmp(array $data, array $file, int $isQueue = 0): array
    {
        $excel = new \PHPExcel();
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->getDefaultColumnDimension()->setWidth(40);
        $sheet->getDefaultRowDimension()->setRowHeight(23);
        // 设置边框
        $sheet->getStyle('A1:E8')->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);

        // 第一行Commercial Invoice
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1:E1')->getFont()->setSize(16);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A1')->setValue('Commercial Invoice');
        // 第二行商业发票
        $sheet->mergeCells('A2:E2');
        $sheet->getStyle('A2:E2')->getFont()->setSize(16);
        $sheet->getStyle('A2:E2')->getFont()->setBold(true);
        $sheet->getStyle('A2:E2')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getCell('A2')->setValue('商业发票');
        // 第三行合同编号和交易日期
        $sheet->mergeCells('A3:B3');
        $sheet->mergeCells('D3:E3');
        $sheet->getStyle('A3:B3')->getFont()->setBold(true);
        $sheet->getCell('A3')->setValue('Contract No: ' . $data['contract_no']);
        $sheet->getCell('C3')->setValue('交易日期: ');

        $sheet->getStyle('A4:E9')->getFont()->setBold(true);
        // 第四行 固定
        $sheet->mergeCells('A4:B4');
        $sheet->mergeCells('C4:E4');
        $sheet->getCell('A4')->setValue('Shipper/Exporter(complete name and address)');
        $sheet->getCell('C4')->setValue('Consignee(complete name and address)');
        // 第五行 公司
        $sheet->getCell('A5')->setValue('Company');
        $sheet->getCell('C5')->setValue('Company');
        $sheet->mergeCells('D5:E5');
        $sheet->getCell('B5')->setValue($data['supplier_info']['company']);
        $sheet->getCell('D5')->setValue($data['consignee_info']['company']);
        // 第六行
        $sheet->mergeCells('A6:B6');
        $sheet->mergeCells('C6:E6');
        $sheet->getCell('A6')->setValue('Add.: ' . $data['supplier_info']['address']);
        $sheet->getCell('C6')->setValue('Add.: ' . $data['consignee_info']['address']);
        // 第六行
        $sheet->mergeCells('A7:B7');
        $sheet->mergeCells('C7:E7');
        $sheet->getCell('A7')->setValue('Tel: ' . $data['supplier_info']['telephone']);
        $sheet->getCell('C7')->setValue('Tel: ' . $data['consignee_info']['telephone']);
        // 第七行
        $sheet->mergeCells('A8:B8');
        $sheet->mergeCells('C8:E8');
        $sheet->getCell('A8')->setValue('Fax: ' . $data['supplier_info']['fax']);
        $sheet->getCell('C8')->setValue('Fax: ' . $data['consignee_info']['fax']);
        // 第八和第九行
        $header = [
            'A' => ['Product Name & Description of Goods', '品名及货物描述'],
            'C' => ['Qty(PCS)', '数量'],
            'D' => ['Unit Value(CNY)', '单价'],
            'E' => ['Total Value(CNY)', '总价'],
        ];
        $sheet->getStyle('A9:E10')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->mergeCells('A9:B9');
        $sheet->mergeCells('A10:B10');
        foreach ($header as $k=>$item) {
            $sheet->getCell($k.'9')->setValue($item[0]);
            $sheet->getCell($k.'10')->setValue($item[1]);
        }
        $map = ['B'=>'goods_name', 'C'=>'sum_qty', 'D'=>'single_price', 'E'=>'sum_price'];
        $tableEndRow = 10+count($data['table_data'])+1;
        $sheet->getStyle('A9:E'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A9:E'.$tableEndRow)->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $sheet->getStyle('A1:E'.($tableEndRow+4+2))->getFont()->setName('宋体');
        $number = 1;
        foreach($data['table_data'] as $k=>$v) {
            $rowNum = $k + 11;
            for($colNum='B';$colNum<='E';$colNum++) {
                $sheet->getCell('A' . $rowNum)->setValue($number);
                $sheet->getCell($colNum.$rowNum)->setValue($v[$map[$colNum]]);
            }
            $number++;
        }
        // 总价
        $sheet->getStyle("A$tableEndRow:D$tableEndRow")->getFont()->setBold(true);
        $sheet->mergeCells('C'.$tableEndRow . ':D'.$tableEndRow);
        $sheet->getStyle('C'.$tableEndRow . ':D'.$tableEndRow)->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_RIGHT);
        $sheet->getCell('C'.$tableEndRow)->setValue('Total Invoice Value');
        $sheet->mergeCells('E'.$tableEndRow . ':E'.($tableEndRow + 4));
        // 设置总价水平居中，垂直居中以及边框
        $sheet->getStyle('E'.$tableEndRow . ':E'.($tableEndRow + 4))->getBorders()->getAllBorders()->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
        $sheet->getStyle('E'.$tableEndRow . ':E'.($tableEndRow + 4))->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('E'.$tableEndRow . ':E'.($tableEndRow + 4))->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);
        $sheet->getCell('E'.$tableEndRow)->setValue($data['total_amount']);

        // 发件人与盖章
        $sheet->mergeCells('A'. ($tableEndRow+4+1) . ':B'.($tableEndRow+4+1));
        $sheet->mergeCells('A'. ($tableEndRow+4+2) . ':B'.($tableEndRow+4+2));
        $sheet->getCell('A'. ($tableEndRow+4+1))->setValue("Shipper's Signature & Stamp");
        $sheet->getStyle('A'. ($tableEndRow+4+1))->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('A'. ($tableEndRow+4+1))->getFont()->setBold(true);
        $sheet->getCell('A'. ($tableEndRow+4+2))->setValue("发件人签字、盖章");
        $sheet->getStyle('A'. ($tableEndRow+4+2))->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

        $objWriter = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $savePath = self::makeSavePath($file);
        $objWriter->save($savePath);

        $fileCode = self::addLogExportDownloadFiles($file, $savePath);
        $result = [
            'status' => 1,
            'message' => 'OK',
            'file_code' => $fileCode,
            'file_name' => $file['name']
        ];
        if($isQueue){
            $result['file_path'] = $savePath;
            $fileExt = $file['ext'] ?? self::DEFAULT_FILE_EXT;
            $result['download_url']  =  DS.'download'.DS.$file['path'].DS.$file['name'].'.'.$fileExt;
        }
        return $result;
    }
    
    /**
     * @desc 读取文件指定行数据
     * @author wangwei
     * @date 2019-4-16 19:30:01
     * @param unknown $excelPath
     * @param number $allColumn
     * @param number $currentRow
     * @param number $sheet
     * @param string $type
     * @throws Exception
     * @return array|string
     */
    public static function readExcelByRow($excelPath, $allColumn = 0, $currentRow=1, $sheet = 0, $type = '')
    {
        ini_set('memory_limit', '2048M');
        $data = [];
        try {
            $pathInfo = pathInfo($excelPath);
            $extension = strtolower($pathInfo['extension']);
            // 返回 mime 类型
            if(in_array($extension,['xlsx','xls'])){
                $finfo = finfo_open(FILEINFO_MIME);
                $fileType = finfo_file($finfo, $excelPath);
                finfo_close($finfo);
                $cutpos = strpos($fileType,';');
                if($cutpos){
                    $fileType = substr($fileType,0,$cutpos);
                }
                if($fileType=='application/vnd.ms-office'){
                    $extension = 'xls';
                }else if('application/zip'==$fileType){
                    $extension = 'xlsx';
                }else if('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' == $fileType){
                    $extension = 'xlsx';
                }else if('application/octet-stream' == $fileType){
                    $extension = 'xlsx';
                }
            }
            switch ($extension) {
                case 'xlsx':
                    $phpReader = \PHPExcel_IOFactory::createReader('Excel2007');
                    break;
                case 'xls':
                    $phpReader = \PHPExcel_IOFactory::createReader('Excel5');
                    break;
                case 'csv':
                    $phpReader = \PHPExcel_IOFactory::createReader('CSV');
                    break;
                default:
                    throw new Exception('文件格式不为 xlsx、xls、csv');
            }
        } catch(\Exception $e) {
            throw new Exception($e->getMessage());
        }
        //载入excel文件
        $phpExcel  = $phpReader->load($excelPath);
        //获取工作表总数
        $sheetCount = $phpExcel->getSheetCount();
        //判断是否超过工作表总数，取最小值
        $sheet = $sheet < $sheetCount ? $sheet : $sheetCount;
        //默认读取excel文件中的第一个工作表
        $currentSheet = $phpExcel->getSheet($sheet);
        if(empty($allColumn)) {
            //取得最大列号，这里输出的是大写的英文字母，ord()函数将字符转为十进制，65代表A
            $allColumn = ord($currentSheet->getHighestColumn()) - 0 + 1;
        }
        for($currentColumn = 0; $currentColumn <= $allColumn - 1; $currentColumn++) {
            $val = trim($currentSheet->getCellByColumnAndRow($currentColumn, $currentRow)->getValue());
            if(is_object($val)) {
                $val= $val->__toString();
            }
            $data[] =  $val;
        }
        
        return $data;
    }
    
}

