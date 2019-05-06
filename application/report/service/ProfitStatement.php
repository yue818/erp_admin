<?php
// +----------------------------------------------------------------------
// |
// +----------------------------------------------------------------------
// | File  : ProfitStatement.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-08-04
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
namespace app\report\service;

use app\common\cache\Cache;
use app\common\model\aliexpress\AliexpressAccount;
use app\common\model\amazon\AmazonAccount;
use app\common\model\cd\CdAccount;
use app\common\model\daraz\DarazAccount;
use app\common\model\ebay\EbayAccount;
use app\common\model\FbaOrder;
use app\common\model\FbaOrderDetail;
use app\common\model\fummart\FummartAccount;
use app\common\model\joom\JoomShop;
use app\common\model\jumia\JumiaAccount;
use app\common\model\lazada\LazadaAccount;
use app\common\model\newegg\NeweggAccount;
use app\common\model\oberlo\OberloAccount;
use app\common\model\Order;
use app\common\model\OrderNote;
use app\common\model\OrderPackage;
use app\common\model\pandao\PandaoAccount;
use app\common\model\paytm\PaytmAccount;
use app\common\model\pm\PmAccount;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\umka\UmkaAccount;
use app\common\model\vova\VovaAccount;
use app\common\model\walmart\WalmartAccount;
use app\common\model\yandex\YandexAccount;
use app\common\model\wish\WishAccount;
use app\common\model\zoodmall\ZoodmallAccount;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\common\service\OrderType;
use app\index\service\Currency;
use app\order\service\AmazonSettlementReportSummary;
use app\report\model\ReportExportFiles;
use app\report\queue\ProfitExportQueue;
use erp\AbsServer;
use think\Db;
use think\Exception;
use think\Validate;
use think\Loader;
use app\common\model\OrderDetail as OrderDetailModel;
use app\common\model\WarehouseGoods as WarehouseGoodsModel;
use app\common\model\User as UserModel;
use app\common\model\Department as DepartmentModel;
use app\index\service\DepartmentUserMapService as DepartmentUserMapService;
use app\index\service\ChannelAccount;
use app\common\traits\Export;
use app\report\service\PerformanceService;

Loader::import('phpExcel.PHPExcel', VENDOR_PATH);


class ProfitStatement extends AbsServer
{

    use Export;

    protected $receiptRate = [
        'yandex' => 0.006,
        'zoodmall' => 0.006,
        'oberlo' => 0.006,
        'amazon' => 0.006,
        'aliExpress' => 0.006,
        'fba' => 0.006,
        'joom' => 0.006,
        'cd' => 0.006,
        'newegg' => 0.006,
        'lazada' => 0.006,
        'shopee' => 0.006,
        'paytm' => 0.006,
        'pandao' => 0.006,
        'walmart' => 0.006,
        'jumia' => 0.006,
        'vova' => 0.006,
        'umka' => 0.006,
        'wish' => 0.004,
        'priceMinister' => 0.006,
        'daraz' => 0.006,
        'fummart' => 0.006,
    ];

    protected $colMap = [
        'amazon' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '订单类型', 'width' => 15],
                'C' => ['title' => '账号简称', 'width' => 10],
                'D' => ['title' => '站点', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 10],
                'G' => ['title' => '销售主管', 'width' => 10],
                'H' => ['title' => '包裹数', 'width' => 10],
                'I' => ['title' => '平台订单号', 'width' => 30],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '邮寄方式', 'width' => 20],
                'O' => ['title' => '包裹号', 'width' => 20],
                'P' => ['title' => '跟踪号', 'width' => 20],
                'Q' => ['title' => '物流商单号', 'width' => 20],
                'R' => ['title' => '总售价原币', 'width' => 10],
                'S' => ['title' => '渠道成交费原币', 'width' => 10],
                'T' => ['title' => '币种', 'width' => 10],
                'U' => ['title' => '订单数', 'width' => 15],
                'V' => ['title' => '汇率', 'width' => 10],
                'W' => ['title' => '总售价CNY', 'width' => 15],
                'X' => ['title' => '渠道成交费（CNY）', 'width' => 20],
                'Y' => ['title' => '收款费用(CNY)', 'width' => 15],
                'Z' => ['title' => '商品成本', 'width' => 15],
                'AA' => ['title' => '物流费用', 'width' => 15],
                'AB' => ['title' => '头程费用', 'width' => 15],
                'AC' => ['title' => '转运费', 'width' => 30],
                'AD' => ['title' => '利润', 'width' => 10],
                'AE' => ['title' => '货品总数', 'width' => 15],
                'AF' => ['title' => '订单备注', 'width' => 30],
                'AG' => ['title' => '物流商', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'order_type' => ['col' => 'B', 'type' => 'str'],
                'account_code' => ['col' => 'C', 'type' => 'str'],
                'site_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'supervisor_name' => ['col' => 'G', 'type' => 'str'],
                'order_package_num' => ['col' => 'H', 'type' => 'str'],
                'channel_order_number' => ['col' => 'I', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'shipping_name' => ['col' => 'N', 'type' => 'str'],
                'package_number' => ['col' => 'O', 'type' => 'str'],
                'shipping_number' => ['col' => 'P', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'order_amount' => ['col' => 'R', 'type' => 'price'],
                'channel_cost' => ['col' => 'S', 'type' => 'str'],
                'currency_code' => ['col' => 'T', 'type' => 'str'],
                'order_quantity' => ['col' => 'U', 'type' => 'str'],
                'rate' => ['col' => 'V', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'W', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'X', 'type' => 'str'],
                'receipt_fee' => ['col' => 'Y', 'type' => 'str'],
                'goods_cost' => ['col' => 'Z', 'type' => 'str'],
                //   'package_fee' => ['col' => 'Z', 'type' => 'str'],
                'shipping_fee' => ['col' => 'AA', 'type' => 'str'],
                'first_fee' => ['col' => 'AB', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AC', 'type' => 'str'],
                'profit' => ['col' => 'AD', 'type' => 'str'],
                'sku_count' => ['col' => 'AE', 'type' => 'str'],
                'order_note' => ['col' => 'AF', 'type' => 'str'],
                'carrier' => ['col' => 'AG', 'type' => 'str'],
            ]
        ],
        'wish' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '订单类型', 'width' => 15],
                'C' => ['title' => '平台订单号', 'width' => 30],
                'D' => ['title' => '包裹数', 'width' => 10],
                'E' => ['title' => '账号简称', 'width' => 10],
                'F' => ['title' => '销售员', 'width' => 10],
                'G' => ['title' => '销售组长', 'width' => 10],
                'H' => ['title' => '国家', 'width' => 10],
                'I' => ['title' => '编码', 'width' => 10],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '邮寄方式', 'width' => 20],
                'O' => ['title' => '包裹号', 'width' => 20],
                'P' => ['title' => '跟踪号', 'width' => 20],
                'R' => ['title' => '币种', 'width' => 10],
                'S' => ['title' => '订单数', 'width' => 15],
                'T' => ['title' => '汇率', 'width' => 10],
                'U' => ['title' => '物流商单号', 'width' => 20],
                'V' => ['title' => '总售价CNY', 'width' => 15],
                'W' => ['title' => '渠道成交CNY', 'width' => 20],
                'X' => ['title' => '收款费用CNY', 'width' => 15],
                'Y' => ['title' => '商品成本', 'width' => 15],
                'AA' => ['title' => '物流费用', 'width' => 15],
                'AB' => ['title' => '头程费用', 'width' => 15],
                'AC' => ['title' => '转运费', 'width' => 30],
                'AD' => ['title' => '利润', 'width' => 10],
                'AE' => ['title' => '货品总数', 'width' => 15],
                'AF' => ['title' => '订单备注', 'width' => 30],
                'AG' => ['title' => '物流商', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'order_type' => ['col' => 'B', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'order_package_num' => ['col' => 'D', 'type' => 'str'],
                'account_code' => ['col' => 'E', 'type' => 'str'],
                'seller_name' => ['col' => 'F', 'type' => 'str'],
                'team_leader_name' => ['col' => 'G', 'type' => 'str'],
                'country_code' => ['col' => 'H', 'type' => 'str'],
                'zipcode' => ['col' => 'I', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'shipping_name' => ['col' => 'N', 'type' => 'str'],
                'package_number' => ['col' => 'O', 'type' => 'str'],
                'shipping_number' => ['col' => 'P', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'currency_code' => ['col' => 'R', 'type' => 'str'],
                'order_quantity' => ['col' => 'S', 'type' => 'str'],
                'rate' => ['col' => 'T', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'U', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'V', 'type' => 'str'],
                'receipt_fee' => ['col' => 'W', 'type' => 'str'],
                'goods_cost' => ['col' => 'X', 'type' => 'str'],
                //	'package_fee' => ['col' => 'Y', 'type' => 'str'],
                'shipping_fee' => ['col' => 'Z', 'type' => 'str'],
                'first_fee' => ['col' => 'AA', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AB', 'type' => 'str'],
                'profit' => ['col' => 'AC', 'type' => 'str'],
                'sku_count' => ['col' => 'AD', 'type' => 'str'],
                'order_note' => ['col' => 'AE', 'type' => 'str'],
                'carrier' => ['col' => 'AF', 'type' => 'str'],
            ]
        ],
        'fba' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '账号简称', 'width' => 10],
                'D' => ['title' => '站点', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 10],
                'G' => ['title' => '销售主管', 'width' => 10],
                //'H' => ['title' => '包裹数', 'width' => 10],
                //'I' => ['title' => '平台订单号', 'width' => 30],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '邮寄方式', 'width' => 20],
                'O' => ['title' => '包裹号', 'width' => 20],
                'P' => ['title' => '跟踪号', 'width' => 20],
                'Q' => ['title' => '物流商单号', 'width' => 20],
                'R' => ['title' => '总售价原币', 'width' => 10],
                'S' => ['title' => '渠道成交费原币', 'width' => 10],
                'T' => ['title' => '币种', 'width' => 10],
                'U' => ['title' => '订单数', 'width' => 10],
                'V' => ['title' => '汇率', 'width' => 10],
                'W' => ['title' => '总售价CNY', 'width' => 15],
                'X' => ['title' => '渠道成交费（CNY）', 'width' => 20],
                'Y' => ['title' => '收款费用(CNY)', 'width' => 15],
                'Z' => ['title' => '商品成本', 'width' => 15],
                // 'Z' => ['title' => '包装费用', 'width' => 15],
                'AA' => ['title' => 'FBA运费', 'width' => 15],
                'AB' => ['title' => '头程费用', 'width' => 15],
                'AC' => ['title' => '利润', 'width' => 10],
                'AD' => ['title' => '货品总数', 'width' => 15],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'account_code' => ['col' => 'C', 'type' => 'str'],
                'site_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'supervisor_name' => ['col' => 'G', 'type' => 'str'],
                'order_package_num' => ['col' => 'H', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'shipping_name' => ['col' => 'N', 'type' => 'str'],
                //'package_number' => ['col' => 'O', 'type' => 'str'],
                'shipping_number' => ['col' => 'P', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'order_amount' => ['col' => 'R', 'type' => 'price'],
                'channel_cost' => ['col' => 'S', 'type' => 'str'],
                'currency_code' => ['col' => 'T', 'type' => 'str'],
                'order_quantity' => ['col' => 'U', 'type' => 'str'],
                'rate' => ['col' => 'V', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'W', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'X', 'type' => 'str'],
                'receipt_fee' => ['col' => 'Y', 'type' => 'str'],
                'goods_cost' => ['col' => 'Z', 'type' => 'str'],
                //   'package_fee' => ['col' => 'Z', 'type' => 'str'],
                'fba_shipping_fee' => ['col' => 'AA', 'type' => 'str'],
                'first_fee' => ['col' => 'AB', 'type' => 'str'],
                'profit' => ['col' => 'AC', 'type' => 'str'],
                'sku_count' => ['col' => 'AD', 'type' => 'str'],
            ]
        ],
        'aliExpress' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '订单类型', 'width' => 15],
                'C' => ['title' => '平台订单号', 'width' => 30],
                'D' => ['title' => '包裹数', 'width' => 10],
                'E' => ['title' => '账号简称', 'width' => 10],
                'F' => ['title' => '付款日期', 'width' => 15],
                'G' => ['title' => '发货日期', 'width' => 15],
                'H' => ['title' => '仓库类型', 'width' => 15],
                'I' => ['title' => '发货仓库', 'width' => 20],
                'J' => ['title' => '邮寄方式', 'width' => 20],
                'K' => ['title' => '包裹号', 'width' => 20],
                'L' => ['title' => '跟踪号', 'width' => 20],
                'M' => ['title' => '物流商单号', 'width' => 20],
                'N' => ['title' => '总售价原币', 'width' => 15],
                'O' => ['title' => '渠道成交费原币', 'width' => 20],
                'P' => ['title' => '币种', 'width' => 10],
                'Q' => ['title' => '订单数', 'width' => 10],
                'R' => ['title' => '汇率', 'width' => 10],
                'S' => ['title' => '总售价CNY', 'width' => 15],
                'T' => ['title' => '渠道成交费CNY', 'width' => 20],
                'U' => ['title' => '收款费用CNY', 'width' => 20],
                'V' => ['title' => '商品成本', 'width' => 15],
                //'U' => ['title' => '包装费用', 'width' => 15],
                'W' => ['title' => '物流费用', 'width' => 15],
                'X' => ['title' => '转运费', 'width' => 30],
                'Y' => ['title' => '利润', 'width' => 10],
                'Z' => ['title' => '货品总数', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '物流商', 'width' => 30],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'order_type' => ['col' => 'B', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'order_package_num' => ['col' => 'D', 'type' => 'str'],
                'account_code' => ['col' => 'E', 'type' => 'str'],
                'pay_time' => ['col' => 'F', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'G', 'type' => 'str'],
                'warehouse_type' => ['col' => 'H', 'type' => 'str'],
                'warehouse_name' => ['col' => 'I', 'type' => 'str'],
                'shipping_name' => ['col' => 'J', 'type' => 'str'],
                'package_number' => ['col' => 'K', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'process_code' => ['col' => 'M', 'type' => 'str'],
                'order_amount' => ['col' => 'N', 'type' => 'price'],
                'channel_cost' => ['col' => 'O', 'type' => 'str'],
                'currency_code' => ['col' => 'P', 'type' => 'str'],
                'order_quantity' => ['col' => 'Q', 'type' => 'str'],
                'rate' => ['col' => 'R', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'S', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'T', 'type' => 'str'],
                'receipt_fee' => ['col' => 'U', 'type' => 'str'],
                'goods_cost' => ['col' => 'V', 'type' => 'str'],
                //'package_fee' => ['col' => 'U', 'type' => 'str'],
                'shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'X', 'type' => 'str'],
                'profit' => ['col' => 'Y', 'type' => 'str'],
                'sku_count' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'carrier' => ['col' => 'AB', 'type' => 'str'],
            ]
        ],
        'ebay' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '订单类型', 'width' => 15],
                'C' => ['title' => '平台订单号', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '包裹数', 'width' => 10],
                'F' => ['title' => '付款日期', 'width' => 15],
                'G' => ['title' => '发货日期', 'width' => 15],
                'H' => ['title' => '仓库类型', 'width' => 15],
                'I' => ['title' => '发货仓库', 'width' => 20],
                'J' => ['title' => '邮寄方式', 'width' => 20],
                'K' => ['title' => '包裹号', 'width' => 20],
                'L' => ['title' => '跟踪号', 'width' => 20],
                'M' => ['title' => '物流商单号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 10],
                'O' => ['title' => '订单数', 'width' => 10],
                'P' => ['title' => '汇率', 'width' => 10],
                'Q' => ['title' => '总售价CNY', 'width' => 15],
                'R' => ['title' => '渠道成交费CNY', 'width' => 20],
                'S' => ['title' => 'PayPal费CNY', 'width' => 20],
                'T' => ['title' => '货币转换费CNY', 'width' => 20],
                'U' => ['title' => '商品成本', 'width' => 15],
                'V' => ['title' => '物流费用', 'width' => 15],
                'W' => ['title' => '头程费用', 'width' => 15],
                'X' => ['title' => '转运费', 'width' => 30],
                'Y' => ['title' => '利润', 'width' => 10],
                'Z' => ['title' => '货品总数', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '物流商', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'order_type' => ['col' => 'B', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'order_package_num' => ['col' => 'E', 'type' => 'str'],
                'pay_time' => ['col' => 'F', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'G', 'type' => 'str'],
                'warehouse_type' => ['col' => 'H', 'type' => 'str'],
                'warehouse_name' => ['col' => 'I', 'type' => 'str'],
                'shipping_name' => ['col' => 'J', 'type' => 'str'],
                'package_number' => ['col' => 'K', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'process_code' => ['col' => 'M', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'mc_fee_CNY' => ['col' => 'S', 'type' => 'str'],
                'conversion_fee_CNY' => ['col' => 'T', 'type' => 'str'],
                //	'receipt_fee' => ['col' => 'T', 'type' => 'str'],
                'goods_cost' => ['col' => 'U', 'type' => 'str'],
                //    'package_fee' => ['col' => 'U', 'type' => 'str'],
                'shipping_fee' => ['col' => 'V', 'type' => 'str'],
                'first_fee' => ['col' => 'W', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'X', 'type' => 'str'],
                'profit' => ['col' => 'Y', 'type' => 'str'],
                'sku_count' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'carrier' => ['col' => 'AB', 'type' => 'str'],

            ]
        ],
        'joom' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '店铺简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收费费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'M', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'lazada' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价CNY', 'width' => 15],
                'R' => ['title' => '渠道成交费CNY', 'width' => 20],
                'S' => ['title' => '收款费用CNY', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'shopee' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'paytm' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收费费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'pandao' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'walmart' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'jumia' => [   // 不一致
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '总售价', 'width' => 15],
                'O' => ['title' => '渠道成交费', 'width' => 20],
                'P' => ['title' => '收费费用', 'width' => 10],
                'Q' => ['title' => '美元汇率', 'width' => 20],
                'R' => ['title' => '售价奈拉', 'width' => 20],
                'S' => ['title' => '奈拉汇率', 'width' => 20],
                'T' => ['title' => '币种', 'width' => 10],
                'U' => ['title' => '订单数', 'width' => 10],
                'V' => ['title' => '汇率', 'width' => 10],
                'W' => ['title' => '总售价CNY', 'width' => 15],
                'X' => ['title' => '商品成本', 'width' => 15],
                'Y' => ['title' => '物流费用', 'width' => 15],
                'Z' => ['title' => '头程报关费', 'width' => 10],
                'AA' => ['title' => '转运费', 'width' => 10],
                'AB' => ['title' => '利润', 'width' => 15],
                'AC' => ['title' => '估算邮费', 'width' => 30],
                'AD' => ['title' => '订单类型', 'width' => 15],
                'AE' => ['title' => '订单备注', 'width' => 30],
                'AF' => ['title' => '总售价原币', 'width' => 10],
                'AG' => ['title' => '物流商', 'width' => 10],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'M', 'type' => 'str'],
                'order_amount' => ['col' => 'N', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'O', 'type' => 'str'],
                'receipt_fee' => ['col' => 'P', 'type' => 'str'],
                'rate_NL' => ['col' => 'Q', 'type' => 'str'],
                'rate_USD' => ['col' => 'S', 'type' => 'str'],
                'selling_price' => ['col' => 'T', 'type' => 'str'],
                'currency_code' => ['col' => 'U', 'type' => 'str'],
                'order_quantity' => ['col' => 'V', 'type' => 'str'],
                'rate' => ['col' => 'S', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'W', 'type' => 'price'],
                'goods_cost' => ['col' => 'X', 'type' => 'str'],
                'shipping_fee' => ['col' => 'Y', 'type' => 'str'],
                'first_fee' => ['col' => 'Z', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AA', 'type' => 'str'],
                'profit' => ['col' => 'AB', 'type' => 'str'],
                'estimated_fee' => ['col' => 'AC', 'type' => 'str'],
                'order_type' => ['col' => 'AD', 'type' => 'str'],
                'order_note' => ['col' => 'AE', 'type' => 'str'],
                'carrier' => ['col' => 'AF', 'type' => 'str'],
            ]
        ],
        'vova' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'umka' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'cd' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'newegg' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'oberlo' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'B' => ['title' => '平台订单号', 'width' => 15],
                'C' => ['title' => '包裹数', 'width' => 30],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 15],
                'G' => ['title' => '付款日期', 'width' => 15],
                'H' => ['title' => '发货日期', 'width' => 15],
                'I' => ['title' => '仓库', 'width' => 20],
                'J' => ['title' => '发货仓库', 'width' => 20],
                'K' => ['title' => '运输方式', 'width' => 20],
                'L' => ['title' => '包裹号', 'width' => 20],
                'M' => ['title' => '跟踪号', 'width' => 20],
                'N' => ['title' => '币种', 'width' => 15],
                'O' => ['title' => '订单数', 'width' => 15],
                'P' => ['title' => '汇率', 'width' => 15],
                'Q' => ['title' => '总售价', 'width' => 15],
                'R' => ['title' => '渠道成交费', 'width' => 20],
                'S' => ['title' => '收款费用', 'width' => 10],
                'T' => ['title' => '商品成本', 'width' => 10],
                'U' => ['title' => '物流费用', 'width' => 20],
                'V' => ['title' => '头程报关费', 'width' => 15],
                'W' => ['title' => '转运费', 'width' => 10],
                'X' => ['title' => '利润', 'width' => 15],
                'Y' => ['title' => '估算邮费', 'width' => 15],
                'Z' => ['title' => '订单类型', 'width' => 15],
                'AA' => ['title' => '订单备注', 'width' => 30],
                'AB' => ['title' => '总售价原币', 'width' => 10],
                'AC' => ['title' => '物流商', 'width' => 10],

            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'B', 'type' => 'str'],
                'order_package_num' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'pay_time' => ['col' => 'G', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'H', 'type' => 'str'],
                'warehouse_type' => ['col' => 'I', 'type' => 'str'],
                'warehouse_name' => ['col' => 'J', 'type' => 'str'],
                'shipping_name' => ['col' => 'K', 'type' => 'str'],
                'package_number' => ['col' => 'L', 'type' => 'str'],
                'shipping_number' => ['col' => 'L', 'type' => 'str'],
                'currency_code' => ['col' => 'N', 'type' => 'str'],
                'order_quantity' => ['col' => 'O', 'type' => 'str'],
                'rate' => ['col' => 'P', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'Q', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'R', 'type' => 'str'],
                'receipt_fee' => ['col' => 'S', 'type' => 'str'],
                'goods_cost' => ['col' => 'T', 'type' => 'str'],
                'shipping_fee' => ['col' => 'U', 'type' => 'str'],
                'first_fee' => ['col' => 'V', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'W', 'type' => 'str'],
                'profit' => ['col' => 'X', 'type' => 'str'],
                'estimated_fee' => ['col' => 'Y', 'type' => 'str'],
                'order_type' => ['col' => 'Z', 'type' => 'str'],
                'order_note' => ['col' => 'AA', 'type' => 'str'],
                'order_amount' => ['col' => 'AB', 'type' => 'price'],
                'carrier' => ['col' => 'AC', 'type' => 'str'],
            ]
        ],
        'priceMinister' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'C' => ['title' => '平台订单号', 'width' => 15],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 10],
                'G' => ['title' => '销售主管', 'width' => 10],
                'H' => ['title' => '国家', 'width' => 10],
                'I' => ['title' => '编码', 'width' => 10],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '物流商', 'width' => 20],
                'O' => ['title' => '邮寄方式', 'width' => 20],
                'P' => ['title' => '包裹号', 'width' => 20],
                'Q' => ['title' => '跟踪号', 'width' => 20],
                'R' => ['title' => '物流商单号', 'width' => 20],
                'S' => ['title' => '总售价原币', 'width' => 15],
                'T' => ['title' => '渠道成交原币', 'width' => 20],
                'U' => ['title' => '币种', 'width' => 10],
                'V' => ['title' => '订单数', 'width' => 15],
                'W' => ['title' => '汇率', 'width' => 10],
                'X' => ['title' => '总售价CNY', 'width' => 15],
                'Y' => ['title' => '渠道成交CNY', 'width' => 20],
                'Z' => ['title' => '收款费用CNY', 'width' => 15],
                'AA' => ['title' => '商品成本', 'width' => 15],
                'AB' => ['title' => '物流费用', 'width' => 15],
                'AC' => ['title' => '头程报关费', 'width' => 15],
                'AD' => ['title' => '转运费', 'width' => 10],
                'AE' => ['title' => '利润', 'width' => 30],
                'AF' => ['title' => '货品总数', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'supervisor_name' => ['col' => 'G', 'type' => 'str'],
                'country_code' => ['col' => 'H', 'type' => 'str'],
                'zipcode' => ['col' => 'I', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'carrier' => ['col' => 'N', 'type' => 'str'],
                'shipping_name' => ['col' => 'O', 'type' => 'str'],
                'package_number' => ['col' => 'P', 'type' => 'str'],
                'shipping_number' => ['col' => 'R', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'order_amount' => ['col' => 'S', 'type' => 'str'],
                'channel_cost' => ['col' => 'T', 'type' => 'str'],
                'currency_code' => ['col' => 'U', 'type' => 'str'],
                'order_quantity' => ['col' => 'V', 'type' => 'str'],
                'rate' => ['col' => 'W', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'X', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'Y', 'type' => 'str'],
                'receipt_fee' => ['col' => 'Z', 'type' => 'str'],
                'goods_cost' => ['col' => 'AA', 'type' => 'str'],
                'shipping_fee' => ['col' => 'AB', 'type' => 'str'],
                'first_fee' => ['col' => 'AC', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AD', 'type' => 'str'],
                'profit' => ['col' => 'AE', 'type' => 'str'],
                'sku_count' => ['col' => 'AF', 'type' => 'str'],
            ]
        ],
        'daraz' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'C' => ['title' => '平台订单号', 'width' => 15],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 10],
                'G' => ['title' => '销售主管', 'width' => 10],
                'H' => ['title' => '国家', 'width' => 10],
                'I' => ['title' => '编码', 'width' => 10],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '物流商', 'width' => 20],
                'O' => ['title' => '邮寄方式', 'width' => 20],
                'P' => ['title' => '包裹号', 'width' => 20],
                'Q' => ['title' => '跟踪号', 'width' => 20],
                'R' => ['title' => '物流商单号', 'width' => 20],
                'S' => ['title' => '总售价原币', 'width' => 15],
                'T' => ['title' => '渠道成交原币', 'width' => 20],
                'U' => ['title' => '币种', 'width' => 10],
                'V' => ['title' => '订单数', 'width' => 15],
                'W' => ['title' => '汇率', 'width' => 10],
                'X' => ['title' => '总售价CNY', 'width' => 15],
                'Y' => ['title' => '渠道成交CNY', 'width' => 20],
                'Z' => ['title' => '收款费用', 'width' => 15],
                'AA' => ['title' => '商品成本', 'width' => 15],
                'AB' => ['title' => '物流费用', 'width' => 15],
                'AC' => ['title' => '头程报关费', 'width' => 15],
                'AD' => ['title' => '转运费', 'width' => 10],
                'AE' => ['title' => '利润', 'width' => 30],
                'AF' => ['title' => '货品总数', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'supervisor_name' => ['col' => 'G', 'type' => 'str'],
                'country_code' => ['col' => 'H', 'type' => 'str'],
                'zipcode' => ['col' => 'I', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'carrier' => ['col' => 'N', 'type' => 'str'],
                'shipping_name' => ['col' => 'O', 'type' => 'str'],
                'package_number' => ['col' => 'P', 'type' => 'str'],
                'shipping_number' => ['col' => 'R', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'order_amount' => ['col' => 'S', 'type' => 'str'],
                'channel_cost' => ['col' => 'T', 'type' => 'str'],
                'currency_code' => ['col' => 'U', 'type' => 'str'],
                'order_quantity' => ['col' => 'V', 'type' => 'str'],
                'rate' => ['col' => 'W', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'X', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'Y', 'type' => 'str'],
                'receipt_fee' => ['col' => 'Z', 'type' => 'str'],
                'goods_cost' => ['col' => 'AA', 'type' => 'str'],
                'shipping_fee' => ['col' => 'AB', 'type' => 'str'],
                'first_fee' => ['col' => 'AC', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AD', 'type' => 'str'],
                'profit' => ['col' => 'AE', 'type' => 'str'],
                'sku_count' => ['col' => 'AF', 'type' => 'str'],
            ]
        ],
        'fummart' => [
            'title' => [
                'A' => ['title' => '订单号', 'width' => 30],
                'C' => ['title' => '平台订单号', 'width' => 15],
                'D' => ['title' => '账号简称', 'width' => 10],
                'E' => ['title' => '销售员', 'width' => 10],
                'F' => ['title' => '销售组长', 'width' => 10],
                'G' => ['title' => '销售主管', 'width' => 10],
                'H' => ['title' => '国家', 'width' => 10],
                'I' => ['title' => '编码', 'width' => 10],
                'J' => ['title' => '付款日期', 'width' => 15],
                'K' => ['title' => '发货日期', 'width' => 15],
                'L' => ['title' => '仓库类型', 'width' => 15],
                'M' => ['title' => '发货仓库', 'width' => 20],
                'N' => ['title' => '物流商', 'width' => 20],
                'O' => ['title' => '邮寄方式', 'width' => 20],
                'P' => ['title' => '包裹号', 'width' => 20],
                'Q' => ['title' => '跟踪号', 'width' => 20],
                'R' => ['title' => '物流商单号', 'width' => 20],
                'S' => ['title' => '总售价原币', 'width' => 15],
                'T' => ['title' => '渠道成交原币', 'width' => 20],
                'U' => ['title' => '币种', 'width' => 10],
                'V' => ['title' => '订单数', 'width' => 15],
                'W' => ['title' => '汇率', 'width' => 10],
                'X' => ['title' => '总售价CNY', 'width' => 15],
                'Y' => ['title' => '渠道成交CNY', 'width' => 20],
                'Z' => ['title' => '收款费用CNY', 'width' => 15],
                'AA' => ['title' => '商品成本', 'width' => 15],
                'AB' => ['title' => '物流费用', 'width' => 15],
                'AC' => ['title' => '头程报关费', 'width' => 15],
                'AD' => ['title' => '转运费', 'width' => 10],
                'AE' => ['title' => '利润', 'width' => 30],
                'AF' => ['title' => '货品总数', 'width' => 30],
            ],
            'data' => [
                'order_number' => ['col' => 'A', 'type' => 'str'],
                'channel_order_number' => ['col' => 'C', 'type' => 'str'],
                'account_code' => ['col' => 'D', 'type' => 'str'],
                'seller_name' => ['col' => 'E', 'type' => 'str'],
                'team_leader_name' => ['col' => 'F', 'type' => 'str'],
                'supervisor_name' => ['col' => 'G', 'type' => 'str'],
                'country_code' => ['col' => 'H', 'type' => 'str'],
                'zipcode' => ['col' => 'I', 'type' => 'str'],
                'pay_time' => ['col' => 'J', 'type' => 'time_stamp'],
                'shipping_time' => ['col' => 'K', 'type' => 'str'],
                'warehouse_type' => ['col' => 'L', 'type' => 'str'],
                'warehouse_name' => ['col' => 'M', 'type' => 'str'],
                'carrier' => ['col' => 'N', 'type' => 'str'],
                'shipping_name' => ['col' => 'O', 'type' => 'str'],
                'package_number' => ['col' => 'P', 'type' => 'str'],
                'shipping_number' => ['col' => 'R', 'type' => 'str'],
                'process_code' => ['col' => 'Q', 'type' => 'str'],
                'order_amount' => ['col' => 'S', 'type' => 'str'],
                'channel_cost' => ['col' => 'T', 'type' => 'str'],
                'currency_code' => ['col' => 'U', 'type' => 'str'],
                'order_quantity' => ['col' => 'V', 'type' => 'str'],
                'rate' => ['col' => 'W', 'type' => 'str'],
                'order_amount_CNY' => ['col' => 'X', 'type' => 'price'],
                'channel_cost_CNY' => ['col' => 'Y', 'type' => 'str'],
                'receipt_fee' => ['col' => 'Z', 'type' => 'str'],
                'goods_cost' => ['col' => 'AA', 'type' => 'str'],
                'shipping_fee' => ['col' => 'AB', 'type' => 'str'],
                'first_fee' => ['col' => 'AC', 'type' => 'str'],
                'trans_shipping_fee' => ['col' => 'AD', 'type' => 'str'],
                'profit' => ['col' => 'AE', 'type' => 'str'],
                'sku_count' => ['col' => 'AF', 'type' => 'str'],
            ]
        ],


    ];

    private function initFieldValue($channel_id, $data)
    {
        $dataMap = [];
        switch ($channel_id) {
            case 1:
                $dataMap = $this->colMap['ebay']['data'];
                break;
            case 2:
                if (isset($data['is_fba']) && ChannelAccountConst::channel_fba) {
                    $dataMap = $this->colMap['fba']['data'];
                } else {
                    $dataMap = $this->colMap['amazon']['data'];
                }
                break;
            case 3:
                $dataMap = $this->colMap['wish']['data'];
                break;
            case 4:
                $dataMap = $this->colMap['aliExpress']['data'];
                break;
            case ChannelAccountConst::channel_Joom:
                $dataMap = $this->colMap['joom']['data'];
                break;
            case ChannelAccountConst::channel_Shopee:
                $dataMap = $this->colMap['shopee']['data'];
                break;
            case ChannelAccountConst::channel_Lazada:
                $dataMap = $this->colMap['lazada']['data'];
                break;
            case ChannelAccountConst::channel_Paytm:
                $dataMap = $this->colMap['paytm']['data'];
                break;
            case ChannelAccountConst::channel_Pandao:
                $dataMap = $this->colMap['pandao']['data'];
                break;
            case ChannelAccountConst::channel_Walmart:
                $dataMap = $this->colMap['walmart']['data'];
                break;
            case ChannelAccountConst::Channel_Jumia:
                $dataMap = $this->colMap['jumia']['data'];
                break;
            case ChannelAccountConst::Channel_umka:
                $dataMap = $this->colMap['umka']['data'];
                break;
            case ChannelAccountConst::channel_Vova:
                $dataMap = $this->colMap['vova']['data'];
                break;
            case ChannelAccountConst::channel_CD:
                $dataMap = $this->colMap['cd']['data'];
                break;
            case ChannelAccountConst::channel_Newegg:
                $dataMap = $this->colMap['newegg']['data'];
                break;
            case ChannelAccountConst::channel_Oberlo:
                $dataMap = $this->colMap['oberlo']['data'];
                break;
            case ChannelAccountConst::channel_Zoodmall:
                $dataMap = $this->colMap['oberlo']['data'];
                break;
            case ChannelAccountConst::channel_Yandex:
                $dataMap = $this->colMap['oberlo']['data'];
                break;
            case ChannelAccountConst::channel_PM:
                $dataMap = $this->colMap['priceMinister']['data'];
                break;
            case ChannelAccountConst::channel_Daraz:
                $dataMap = $this->colMap['daraz']['data'];
                break;
            case ChannelAccountConst::channel_Fummart:
                $dataMap = $this->colMap['fummart']['data'];
                break;
        }
        $result = [];
        $is_data = 1;
        foreach ($dataMap as $key => $value) {
            if ($key == 'shipping_number') {
                $is_data = 0;
            }
            if ($is_data) {
                ;
                $result[$key] = isset($data[$key]) ? $data[$key] : '';
            } else {
                $result[$key] = '-';
            }
        }
        return $result;
    }


    /**
     * 获取参数
     * @param array $params
     * @param $key
     * @param $default
     * @return mixed
     */
    public function getParameter(array $params, $key, $default)
    {
        $v = $default;
        if (isset($params[$key]) && $params[$key]) {
            $v = $params[$key];
        }
        return $v;
    }

    /**
     * 查询平台利润报表
     * @param $params
     * @return array
     * @throws Exception
     */
    public function search($params)
    {
        $page = $this->getParameter($params, 'page', 1);
        $pageSize = $this->getParameter($params, 'pageSize', 10);
        $timeField = $this->getParameter($params, 'time_field', '');
        $timeSort = $this->getParameter($params, 'time_sort', 'ASC');
        $params['is_fba'] = param($params, 'is_fba', ChannelAccountConst::channel_not_fba);
        if (!in_array($timeSort, ['ASC', 'DESC'])) $timeSort = 'ASC';
        if (isset($params['is_fba']) && $params['is_fba'] == ChannelAccountConst::channel_fba) {
            $condition = $this->fbaSearchCondition($params);
            $count = $this->fbaCount($condition);
            $searchData = $this->fbaSearch($condition, $page, $pageSize, $timeField, $timeSort);
        } else {
            $condition = $this->getSearchCondition($params);
            $count = $this->searchCount($condition);
            $searchData = $this->doSearch($condition, $page, $pageSize, $timeField, $timeSort, $params['channel_id']);
        }
        $data = $this->assemblyData($searchData);
        return ['page' => $page, 'pageSize' => $pageSize, 'count' => $count, 'data' => $data];
    }

    /**
     * 创建导出文件名
     * @param $channelId
     * @return string
     * @throws Exception
     */
    protected function createExportFileName($channelId, $userId, $params)
    {
        $channelAccountService = new ChannelAccount();
        $fileName = '';
        switch ($channelId) {
            case 1:
                $fileName = 'ebay平台利润报表';
                break;
            case 2:
                if ($params['is_fba'] == ChannelAccountConst::channel_fba) {
                    $fileName = 'FBA平台利润报表';
                } else {
                    $fileName = '亚马逊平台利润报表';
                }
                break;
            case 3:
                $fileName = 'WISH平台利润报表';
                break;
            case 4:
                $fileName = '速卖通平台利润报表';
                break;
            case ChannelAccountConst::channel_Joom:
                $fileName = 'Joom平台利润报表';
                break;
            case ChannelAccountConst::channel_Shopee:
                $fileName = 'Shopee平台利润报表';
                break;
            case ChannelAccountConst::channel_Lazada:
                $fileName = 'Lazada平台利润报表';
                break;
            case ChannelAccountConst::channel_Paytm:
                $fileName = 'Paytm平台利润报表';
                break;
            case ChannelAccountConst::channel_Pandao:
                $fileName = 'Pandao平台利润报表';
                break;
            case ChannelAccountConst::channel_Walmart:
                $fileName = 'Walmart平台利润报表';
                break;
            case ChannelAccountConst::Channel_Jumia:
                $fileName = 'Jumia平台利润报表';
                break;
            case ChannelAccountConst::Channel_umka:
                $fileName = 'Umka平台利润报表';
                break;
            case ChannelAccountConst::channel_Vova:
                $fileName = 'Vova平台利润报表';
                break;
            case ChannelAccountConst::channel_CD:
                $fileName = 'CD平台利润报表';
                break;
            case ChannelAccountConst::channel_Newegg:
                $fileName = 'Newegg平台利润报表';
                break;
            case ChannelAccountConst::channel_Oberlo:
                $fileName = 'Oberlo平台利润报表';
                break;
            case ChannelAccountConst::channel_Zoodmall:
                $fileName = 'Zoodmall平台利润报表';
                break;
            case ChannelAccountConst::channel_Yandex:
                $fileName = 'Yandex平台利润报表';
                break;
            case ChannelAccountConst::channel_PM:
                $fileName = 'PriceMinister平台利润报表';
                break;
            case ChannelAccountConst::channel_Daraz:
                $fileName = 'Daraz平台利润报表';
                break;
            case ChannelAccountConst::channel_Fummart:
                $fileName = 'Fummart平台利润报表';
                break;
            default:
                throw new Exception('不支持的平台');
        }
        $lastID = (new ReportExportFiles())->order('id desc')->value('id');
        $fileName .= ($lastID + 1);
        if (isset($params['site_code']) && $params['site_code']) {
            $fileName .= '_' . $params['site_code'];
        }
        if (isset($params['channel_id']) && isset($params['account_id']) && $params['account_id'] && $params['channel_id']) {
            $account = $channelAccountService->getAccount($params['channel_id'], $params['account_id']);
            $accountCode = param($account, 'code');
            $fileName .= '_' . $accountCode;
        }
        if (isset($params['warehouse_id']) && $params['warehouse_id']) {
            $warehouse_name = Cache::store('warehouse')->getWarehouseNameById($params['warehouse_id']);
            $fileName .= '_' . $warehouse_name;
        }
        if (isset($params['seller_id']) && $params['seller_id']) {
            $seller_name = Cache::store('user')->getOneUserRealname($params['seller_id']);
            $fileName .= '_' . $seller_name;
        }
        if (isset($params['order_number']) && $params['order_number']) {
            $fileName .= '_' . $params['order_number'];
        }

        $fileName .= '_' . $params['time_start'] . '_' . $params['time_end'] . '.xlsx';
        return $fileName;
    }

    /**
     * 申请导出
     * @param $params
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function applyExport($params)
    {
        Db::startTrans();
        try {
            $userId = Common::getUserInfo()->toArray()['user_id'];
            $cacher = Cache::handler();
            $lastApplyTime = $cacher->hget('hash:export_apply', $userId);
            if ($lastApplyTime && time() - $lastApplyTime < 5) {
                throw new Exception('请求过于频繁', 400);
            } else {
                $cacher->hset('hash:export_apply', $userId, time());
            }
            if (!isset($params['channel_id']) || trim($params['channel_id']) == '') {
                throw new Exception('平台未设置', 400);
            }
            $model = new ReportExportFiles();
            $model->applicant_id = $userId;
            $model->apply_time = time();
            $params['is_fba'] = param($params, 'is_fba', ChannelAccountConst::channel_not_fba);
            $model->export_file_name = $this->createExportFileName($params['channel_id'], $model->applicant_id, $params);
            $model->status = 0;
            if (!$model->save()) {
                throw new Exception('导出请求创建失败', 500);
            }
            $params['file_name'] = $model->export_file_name;
            $params['apply_id'] = $model->id;
            $queuer = new CommonQueuer(ProfitExportQueue::class);
            $queuer->push($params);
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            Db::rollback();
            if ($ex->getCode()) {
                throw $ex;
            } else {
                Cache::handler()->hset(
                    'hash:report_export_apply',
                    $params['apply_id'] . '_' . time(),
                    $ex->getMessage());
                throw new Exception('导出请求创建失败', 500);
            }
        }
    }

    /**
     * 导出数据至excel文件
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function export($params)
    {
        set_time_limit(0);
        try {
            ini_set('memory_limit', '4096M');
            $applyId = $this->getParameter($params, 'apply_id', '');
            if (!$applyId) {
                throw new Exception('导出申请id获取失败');
            }
            $fileName = $this->getParameter($params, 'file_name', '');
            if (!$fileName) {
                throw new Exception('导出文件名未设置');
            }
            $fileName = $this->getParameter($params, 'file_name', '');
            if (!$fileName) {
                throw new Exception('导出文件名未设置');
            }
            $downLoadDir = '/download/platform_profit/';
            $saveDir = ROOT_PATH . 'public' . $downLoadDir;
            if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir . $fileName;
            //创建excel对象
            $writer = new \XLSXWriter();
            $titleMap = [];
            $dataMap = [];
            switch (($params['channel_id'])) {
                case 1:
                    $titleMap = $this->colMap['ebay']['title'];
                    $dataMap = $this->colMap['ebay']['data'];
                    break;
                case 2:
                    if (isset($params['is_fba']) && $params['is_fba'] == ChannelAccountConst::channel_fba) {
                        $titleMap = $this->colMap['fba']['title'];
                        $dataMap = $this->colMap['fba']['data'];
                    } else {
                        $titleMap = $this->colMap['amazon']['title'];
                        $dataMap = $this->colMap['amazon']['data'];
                    }
                    break;
                case 3:
                    $titleMap = $this->colMap['wish']['title'];
                    $dataMap = $this->colMap['wish']['data'];
                    break;
                case 4:
                    $titleMap = $this->colMap['aliExpress']['title'];
                    $dataMap = $this->colMap['aliExpress']['data'];
                    break;
                case ChannelAccountConst::channel_Joom:
                    $titleMap = $this->colMap['joom']['title'];
                    $dataMap = $this->colMap['joom']['data'];
                    break;
                case ChannelAccountConst::channel_Shopee:
                    $titleMap = $this->colMap['shopee']['title'];
                    $dataMap = $this->colMap['shopee']['data'];
                    break;
                case ChannelAccountConst::channel_Lazada:
                    $titleMap = $this->colMap['lazada']['title'];
                    $dataMap = $this->colMap['lazada']['data'];
                    break;
                case ChannelAccountConst::channel_Paytm:
                    $titleMap = $this->colMap['paytm']['title'];
                    $dataMap = $this->colMap['paytm']['data'];
                    break;
                case ChannelAccountConst::channel_Pandao:
                    $titleMap = $this->colMap['pandao']['title'];
                    $dataMap = $this->colMap['pandao']['data'];
                    break;
                case ChannelAccountConst::channel_Walmart:
                    $titleMap = $this->colMap['walmart']['title'];
                    $dataMap = $this->colMap['walmart']['data'];
                    break;
                case ChannelAccountConst::Channel_Jumia:
                    $titleMap = $this->colMap['jumia']['title'];
                    $dataMap = $this->colMap['jumia']['data'];
                    break;
                case ChannelAccountConst::channel_Vova:
                    $titleMap = $this->colMap['vova']['title'];
                    $dataMap = $this->colMap['vova']['data'];
                    break;
                case ChannelAccountConst::Channel_umka:
                    $titleMap = $this->colMap['umka']['title'];
                    $dataMap = $this->colMap['umka']['data'];
                    break;
                case ChannelAccountConst::channel_CD:
                    $titleMap = $this->colMap['cd']['title'];
                    $dataMap = $this->colMap['cd']['data'];
                    break;
                case ChannelAccountConst::channel_Newegg:
                    $titleMap = $this->colMap['newegg']['title'];
                    $dataMap = $this->colMap['newegg']['data'];
                    break;
                case ChannelAccountConst::channel_Oberlo:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap = $this->colMap['oberlo']['data'];
                    break;
                case ChannelAccountConst::channel_Zoodmall:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap = $this->colMap['oberlo']['data'];
                    break;
                case ChannelAccountConst::channel_Yandex:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap = $this->colMap['oberlo']['data'];
                    break;
                case ChannelAccountConst::channel_PM:
                    $titleMap = $this->colMap['priceMinister']['title'];
                    $dataMap = $this->colMap['priceMinister']['data'];
                    break;
                case ChannelAccountConst::channel_Daraz:
                    $titleMap = $this->colMap['daraz']['title'];
                    $dataMap = $this->colMap['daraz']['data'];
                    break;
                case ChannelAccountConst::channel_Fummart:
                    $titleMap = $this->colMap['fummart']['title'];
                    $dataMap = $this->colMap['fummart']['data'];
                    break;
            }
            $title = [];
            foreach ($dataMap as $k => $v) {
                array_push($title, $k);
                $titleMap[$v['col']]['type'] = $v['type'];
            }
            $titleOrderData = [];
            foreach ($titleMap as $t => $tt) {
                if (isset($tt['type']) && $tt['type'] == 'price') {
                    $titleOrderData[$tt['title']] = 'price';
                } else {
                    $titleOrderData[$tt['title']] = 'string';
                }
            }
            $timeField = $this->getParameter($params, 'time_field', '');
            $timeSort = $this->getParameter($params, 'time_sort', 'ASC');
            if (isset($params['is_fba']) && $params['is_fba'] == ChannelAccountConst::channel_fba) {
                $condition = $this->fbaSearchCondition($params);
            } else {
                $condition = $this->getSearchCondition($params);
            }
            //统计需要导出的数据行
            $count = $this->searchCount($condition);
            $pageSize = 10000;
            if (!in_array($timeSort, ['ASC', 'DESC'])) $timeSort = 'ASC';
            $loop = ceil($count / $pageSize);
            $writer->writeSheetHeader('Sheet1', $titleOrderData);
            //分批导出
            for ($i = 0; $i < $loop; $i++) {
                $data = $this->assemblyData($this->doSearch($condition, $i + 1, $pageSize, $timeField, $timeSort, $params['channel_id']), 2, $title);
                foreach ($data as $r) {
                    $writer->writeSheetRow('Sheet1', $r);
                }
                unset($data);
            }
            $writer->writeToFile($fullName);
            if (is_file($fullName)) {
                $applyRecord = ReportExportFiles::get($applyId);
                $applyRecord->exported_time = time();
                $applyRecord->download_url = $downLoadDir . $fileName;
                $applyRecord->status = 1;
                $applyRecord->isUpdate()->save();
            } else {
                throw new Exception('文件写入失败');
            }
        } catch (\Exception $ex) {
            $applyRecord = ReportExportFiles::get($applyId);
            $applyRecord->status = 2;
            $applyRecord->error_message = $ex->getMessage() . $ex->getFile() . $ex->getFile();
            $applyRecord->isUpdate()->save();
            Cache::handler()->hset(
                'hash:report_export',
                $applyId . '_' . time(),
                '申请id: ' . $applyId . ',导出失败:' . $ex->getMessage());
        }
        return true;
    }

    /**
     * 计算获取平台订单的利润
     * @param $channelId
     * @param $recordData
     * @return int
     */
    protected function getPlatformOrderProfit($channelId, $recordData)
    {
        $profit = 0;
        switch ($channelId) {
            case 2: //亚马逊
                if (isset($recordData['is_fba'])) {
                    if ($recordData['is_fba'] == ChannelAccountConst::channel_fba) {
                        // 利润 = 总售价CNY - 渠道成交费CNY - 收款费用CNY - 商品成本 - FBA运费- 头程报关费
                        $profit = $recordData['order_amount_CNY']
                            - $recordData['channel_cost_CNY']
                            - abs($recordData['receipt_fee'])
                            - $recordData['goods_cost']
                            - abs($recordData['fba_shipping_fee'])
                            - $recordData['first_fee'];
                    }
                } else {
                    $profit = $recordData['order_amount_CNY'] //总售价
                        - $recordData['channel_cost_CNY']  //渠道成交费
                        - floatval($recordData['receipt_fee']) //收款费用
                        - $recordData['goods_cost'] //商品成本
                        - $recordData['shipping_fee'] //物流费用
                        - $recordData['first_fee']//头程报关费
                        - $recordData['trans_shipping_fee'];// 转运费
                }
                break;
            case 3: //wish
                $profit = $recordData['order_amount_CNY']
                    - $recordData['channel_cost_CNY']
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost']
                    - $recordData['shipping_fee']
                    - $recordData['first_fee']
                    - $recordData['trans_shipping_fee'];// 转运费
                break;
            case 4: //速卖通
                $profit = $recordData['order_amount_CNY']
                    - $recordData['channel_cost_CNY']
                    - $recordData['goods_cost']
                    - $recordData['shipping_fee']
                    - $recordData['trans_shipping_fee']// 转运费
                    - $recordData['first_fee'];
                break;
            case 1: //ebay 货币转换费
                $profit = $recordData['order_amount_CNY']
                    - $recordData['channel_cost_CNY']
                    - $recordData['mc_fee_CNY']
                    - $recordData['conversion_fee_CNY']
                    - $recordData['goods_cost']
                    - $recordData['shipping_fee']
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];

                break;
            case ChannelAccountConst::channel_Joom: //joom 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Shopee: //Shopee 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Lazada: //Lazada 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Paytm: //paytm 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Pandao: //pandao 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Walmart: //walmart 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::Channel_Jumia: //jumia 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['trans_shipping_fee']
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::Channel_umka: //umka 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['trans_shipping_fee']
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Vova: //voma 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_CD: //cd 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['trans_shipping_fee']
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Newegg: //newegg 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Oberlo: //oberlo 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['trans_shipping_fee']
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Zoodmall: //zoodmall 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Yandex://yandex 利润=总售价-渠道成交费-收款费用-商品成本-物流费用-头程报关费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['trans_shipping_fee']
                    - $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Fummart:
                //fummart 利润 = 总售价CNY - 渠道成交费CNY - 收款费用CNY - 商品成本 - 物流费用 - 头程报关费 -转运费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee']//头程报关费
                    - $recordData['trans_shipping_fee'];// 转运费
                break;
            case ChannelAccountConst::channel_PM:
                //priceMinister 利润 = 总售价CNY - 渠道成交费CNY - 收款费用CNY - 商品成本 - 物流费用 - 头程报关费 -转运费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee']//头程报关费
                    - $recordData['trans_shipping_fee'];// 转运费
                break;
            case ChannelAccountConst::channel_Daraz:
                //Daraz 利润 = 总售价CNY - 渠道成交费CNY - 收款费用CNY - 商品成本 - 物流费用 - 头程报关费 -转运费
                $profit = $recordData['order_amount_CNY'] //总售价
                    - $recordData['channel_cost_CNY']  //渠道成交费
                    - floatval($recordData['receipt_fee']) //收款费用
                    - $recordData['goods_cost'] //商品成本
                    - $recordData['shipping_fee'] //物流费用
                    - $recordData['first_fee']//头程报关费
                    - $recordData['trans_shipping_fee'];// 转运费
                break;
        }
        return $profit;

    }

    /**
     * 获取包裹号
     * @param $number
     * @return string
     */
    public function getPackageNumber($number)
    {
        return trim($number);
    }

    /**
     * 组装查询返回数据
     * @param $records
     * @param $type 1-列表 2-导出
     * @param $title
     * @return array
     */
    protected function assemblyData($records, $type = 1, $title = [])
    {
        if (empty($records)) {
            return [];
        }
        $orderNumbers = [];
        $data = [];
        $info = [];
        $packageService = new \app\order\service\PackageService();
        $packageModel = new \app\common\model\OrderPackage();
        $shippingMethod = new \app\warehouse\service\ShippingMethod();
        $order = new \app\order\service\OrderService();
        $performanceService = new PerformanceService();
        $currency = new Currency();
        if ($type == 2) {
            $orderIds = array_column($records, 'id');
            $orderNoteList = (new OrderNote())->where('order_id', 'in', $orderIds)->column('order_id', 'note');
        }
        $rateUSD = $currency->getCurrency('USD');
        foreach ($records as $key => $record) {
            $_data = $record->getData();
            $_data['package_fee'] = 0;//后面算
            $_data['shipping_fee'] = 0;//后面算
            $_data['id'] = strval($_data['id']);
            $_data['team_leader_name'] = '';//销售组长
            $_data['supervisor_name'] = '';//销售主管
            $_data['goods_cost'] = $record['cost'];//商品成本
            $_data['package_goods'] = '';//包裹商品
            $_data['receipt_fee'] = 0;//收款费用
            $_data['conversion_fee'] = 0;//货币转换费
            $_data['mc_fee'] = param($_data, 'mc_fee', 0);
            $_data['conversion_fee_CNY'] = 0;
            $_data['mc_fee_CNY'] = 0;
            $_data['trans_shipping_fee'] = 0;// 转运费
            $_data['country_code'] = param($_data, 'country_code', '');
            $_data['zipcode'] = param($_data, 'zipcode', '');

            if ($type == 2) {
                $_data['shipping_time'] = date('Y-m-d H:i:s', param($_data, 'shipping_time', 0));
            }
            $_data['shipping_time'] = param($_data, 'shipping_time', 0);
            if (!empty($title)) {
                $_data['pay_time'] = date('Y-m-d H:i:s', param($_data, 'pay_time', 0));
            }

            // 订单总售价CNY
            $_data['order_amount_CNY'] = bcmul($_data['order_amount'], $_data['rate'], 4);
            // 渠道成交费CNY
            $_data['channel_cost_CNY'] = bcmul($_data['channel_cost'], $_data['rate'], 4);

            // 计算订单数  售价为零、补差价订单(实际不需要发货) 订单数为零
            if ((isset($_data['belong_type']) && $_data['belong_type'] == 3) || ($_data['pay_fee'] == 0)) {
                $_data['order_quantity'] = 0;
            } else {
                $_data['order_quantity'] = 1;
            }

            if ($type == 2) {
                $_data['order_note'] = $orderNoteList[$_data['id']] ?? ''; //订单备注
            }

            $_data['order_type'] = '';
            $_data['type'] = param($_data, 'type', 0);
            switch ($_data['type']) {
                case 0:
                    $_data['order_type'] = '渠道订单';
                    break;
                case 1:
                    $_data['order_type'] = '手工订单';
                    break;
                case 2:
                    $_data['order_type'] = '刷单订单';
                    break;

            }
            $skuChannelList = $this->skuChannelList();
            if (in_array($_data['channel_id'], $skuChannelList)) {
                // 统计货品总数
                $_data['sku_count'] = OrderDetailModel::where(['order_id' => $_data['id']])->value('sum(sku_quantity)');
            }

            switch ($_data['channel_id']) {
                case 1: //ebay
                    $ebayAccount = EbayAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $ebayAccount ? $ebayAccount->code : '';
                    if ($_data['currency_code'] != 'USD') {
                        $_data['conversion_fee'] = ($_data['order_amount'] - $_data['channel_cost'] - $_data['mc_fee']) * 0.025;
                        $_data['conversion_fee'] < 0 && $_data['conversion_fee'] = 0;
                    }
                    $_data['mc_fee_CNY'] = $_data['mc_fee'] * $_data['rate'];
                    $_data['conversion_fee_CNY'] = $_data['conversion_fee'] * $_data['rate'];

                    break;
                case 2: //amazon
                    $amazonAccount = AmazonAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $amazonAccount ? $amazonAccount->code : '';
                    if (isset($_data['is_fba']) && $_data['is_fba'] == ChannelAccountConst::channel_fba) {
                        $result = (new AmazonSettlementReportSummary())->getSettleDataByOrderNumber($_data['channel_order_number']);
                        $_data['channel_cost'] = sprintf('%.4f', $result['channel_fee']);
                        $_data['channel_cost_CNY'] = abs(bcmul($result['channel_fee'], $_data['rate'], 4));
                        $_data['fba_shipping_fee'] = bcmul($result['fba_shipping'], $_data['rate'], 4);
                        $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                            $this->receiptRate['fba'], 4);
                        $sellerUser = Cache::store('user')->getOneUser($_data['seller_id']);
                        $_data['seller_name'] = $sellerUser['realname'] ?? '';
                        $_data['order_quantity'] = 1; // fba 订单直接计数
                        $fbaDatail = (new FbaOrderDetail())->field('id,fba_order_id, sum(sku_quantity) as sku_count')
                            ->where('fba_order_id', $_data['id'])->find();
                        $_data['sku_count'] = $fbaDatail['sku_count'];
                    } else {
                        $_data['receipt_fee'] = bcmul(($_data['order_amount_CNY'] - $_data['channel_cost_CNY']),
                            $this->receiptRate['amazon'], 4);
                    }
                    break;
                case 3: //wish
                    $wishAccount = WishAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $wishAccount ? $wishAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['wish'], 4);
                    break;
                case 4: //aliExpress
                    $aliexpressAccount = AliexpressAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $aliexpressAccount ? $aliexpressAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['aliExpress'], 4);
                    $_data['conversion_fee_CNY'] = $_data['conversion_fee'] * $_data['rate'];
                    break;
                case ChannelAccountConst::channel_Joom: //joom
                    $joomAccount = JoomShop::get($_data['channel_account_id']);
                    $_data['account_code'] = $joomAccount ? $joomAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['joom'], 4);
                    break;
                case ChannelAccountConst::channel_Shopee: //shopee
                    $shopeeAccount = ShopeeAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $shopeeAccount ? $shopeeAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['shopee'], 4);

                    break;
                case ChannelAccountConst::channel_Lazada: //lazada
                    $lazadaAccount = LazadaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $lazadaAccount ? $lazadaAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['lazada'], 4);
                    break;
                case ChannelAccountConst::channel_Paytm: //paytm
                    $paytmAccount = PaytmAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $paytmAccount ? $paytmAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['paytm'], 4);
                    break;
                case ChannelAccountConst::channel_Pandao: //pandao
                    $pandaoAccount = PandaoAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $pandaoAccount ? $pandaoAccount->code : '';
                    if ($_data['channel_cost'] <= 0) {
                        $_data['channel_cost'] = $_data['order_amount'] * 0.06;
                    }
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['pandao'], 4);
                    break;
                case ChannelAccountConst::channel_Walmart: //walmart
                    $walmartAccount = WalmartAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $walmartAccount ? $walmartAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['walmart'], 4);
                    break;
                case ChannelAccountConst::Channel_Jumia: //jumia
                    $jumiaAccount = JumiaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $jumiaAccount ? $jumiaAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['jumia'], 4);//收款费用
                    $_data['rate_USD'] = $rateUSD['USD'];//美元汇率
                    $_data['selling_price'] = $_data['order_amount'] / $_data['rate_USD'];//售价奈拉
                    $_data['order_amount_CNY'] = $_data['selling_price'] * $_data['rate'];//总售价CNY
                    break;
                case ChannelAccountConst::channel_Vova: //voma
                    $vovaAccount = VovaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $vovaAccount ? $vovaAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['vova'], 4);
                    break;
                case ChannelAccountConst::Channel_umka: //umka
                    $umkaAccount = UmkaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $umkaAccount ? $umkaAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['umka'], 4); //收款费用
                    break;
                case ChannelAccountConst::channel_CD: //cd
                    $cdAccount = CdAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $cdAccount ? $cdAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['cd'], 4);//收款费用
                    break;
                case ChannelAccountConst::channel_Newegg: //newegg
                    $neweggAccount = NeweggAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $neweggAccount ? $neweggAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['newegg'], 4);//收款费用
                    break;
                case ChannelAccountConst::channel_Oberlo: //oberlo
                    $oberloAccount = OberloAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $oberloAccount ? $oberloAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['oberlo'], 4);//收款费用
                    break;
                case ChannelAccountConst::channel_Zoodmall: //zoodmall
                    $zoodmallAccount = ZoodmallAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $zoodmallAccount ? $zoodmallAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['zoodmall'], 4); //收款费用
                    break;
                case ChannelAccountConst::channel_Yandex: //yandex
                    $yandexAccount = YandexAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $yandexAccount ? $yandexAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount'] * 0.15 * $_data['rate'];//渠道成交费
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['yandex'], 4);//收款费用

                    break;
                case ChannelAccountConst::channel_PM: //priceMinister
                    $priceMinisterAccount = PmAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $priceMinisterAccount ? $priceMinisterAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['priceMinister'], 4);
                    break;
                case ChannelAccountConst::channel_Daraz: //daraz
                    $darazAccount = DarazAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $darazAccount ? $darazAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['daraz'], 4);
                    break;
                case ChannelAccountConst::channel_Fummart: //fummart
                    $fummartAccount = FummartAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $fummartAccount ? $fummartAccount->code : '';
                    $_data['receipt_fee'] = bcmul($_data['order_amount_CNY'] - $_data['channel_cost_CNY'],
                        $this->receiptRate['fummart'], 4);
                    break;
            }
            $_data['team_leader_name'] = '';
            $_data['supervisor_name'] = '';
            if ($record->seller_id) {
                $user = $performanceService->getLeaderDirector($record->seller_id);
                $_data['team_leader_name'] = $user['sale_group_leader'];
                $_data['supervisor_name'] = $user['sale_director'];
            }
            unset($_data['seller_id']);

            $shipping_name = [];
            $shipping_number = [];
            $package_number = [];
            $package_fee = [];
            $shipping_fee = [];
            $warehouse_name = [];
            $warehouse_type = [];
            $shipping_time = [];
            $process_code = [];
            $_data['warehouse_type'] = '';
            $_data['warehouse_name'] = '';

            $packageIds = $packageService->getPackageIdByOrderId($_data['id']);
            $_data['order_package_num'] = count($packageIds);
            foreach ($packageIds as $id) {
                $package = $packageModel->where('id', $id)->field('order_id, shipping_id, warehouse_id, shipping_time, shipping_number, process_code, number as package_number, package_fee, shipping_fee')->find();
                if ($package['warehouse_id']) {
                    $warehouse = Cache::store('warehouse')->getWarehouse($package['warehouse_id']);
                    if (isset($warehouse['type']) && !empty($warehouse['type'])) {
                        $_data['warehouse_type'] = $this->getWarehouseType($warehouse['type']);
                        $warehouse_type[$id] = $this->getWarehouseType($warehouse['type']);
                    }
                    if (isset($warehouse['name']) && !empty($warehouse['name'])) {
                        $_data['warehouse_name'] = $warehouse['name'];
                        $warehouse_name[$id] = $warehouse['name'];
                    }
                }

                $shipping_name[$id] = $package['shipping_id'] ? $shippingMethod->getShippingMethodInfo($package['shipping_id'], 'shortname', '') : '';
                #跟踪号
                //$shipping_number[$id] = ' '.$package['shipping_number'];//避免科学计数法
                $shipping_number[$id] = $package['shipping_number'];//避免科学计数法
                #包裹号
                $package_number[$id] = $this->getPackageNumber($package['package_number']);
                #发货时间
                if ($type == 2) {
                    $shipping_time[$id] = date('Y-m-d H:i:s', param($package, 'shipping_time', 0));
                }
                $shipping_time[$id] = $package['shipping_time'];
                #物流商单号
                $process_code[$id] = ' ' . $package['process_code'];
                $shipping_fee[$id] = 0;//运费
                $package_fee[$id] = 0;//包装费

                $is_merge = $order->isMerge([$id]);//是否合并订单
                if ($is_merge) {
                    if ($package['order_id'] == $_data['id']) {//运费放在包裹存的订单
                        $shipping_fee[$id] = $package['shipping_fee'];
                        $package_fee[$id] = $package['package_fee'];
                    }
                } else {
                    $shipping_fee[$id] = $package['shipping_fee'];
                    $package_fee[$id] = $package['package_fee'];
                }

                $_data['package_fee'] += $package_fee[$id];
                $_data['shipping_fee'] += $shipping_fee[$id];

            }
            // 判断是否有warehouse_id
            if (isset($_data['warehouse_id'])) {
                list($warehouseName, $warehouseType) = $this->warehouseInfo($_data['warehouse_id']);
                $_data['warehouse_nmae'] = $warehouseName;
                $_data['warehouse_type'] = $warehouseType;
            }
            //转运费
            $trans_shipping_fee = (new OrderDetailService())->getTransShippingFee($packageIds);
            $_data['trans_shipping_fee'] = $trans_shipping_fee ?? 0;

            $_data['profit'] = $this->getPlatformOrderProfit($_data['channel_id'], $_data);
            if (in_array($_data['order_number'], $orderNumbers)) {
                $_data['order_amount'] = 0;
                $_data['channel_cost'] = 0;
                $_data['order_amount_CNY'] = 0;
                $_data['channel_cost_CNY'] = 0;
                $_data['receipt_fee'] = 0;
                $_data['first_fee'] = 0;
                $_data['trans_shipping_fee'] = 0;
                $_data['profit'] = 0;
            } else {
                $orderNumbers[] = $_data['order_number'];
            }

            $_data['order_amount_CNY'] = sprintf("%.4f", param($_data, 'order_amount_CNY', 0));
            $_data['channel_cost_CNY'] = sprintf("%.4f", param($_data, 'channel_cost_CNY', 0));
            $_data['receipt_fee'] = sprintf("%.4f", $_data['receipt_fee']);
            $_data['mc_fee_CNY'] = sprintf("%.4f", $_data['mc_fee_CNY']);
            $_data['profit'] = sprintf("%.4f", $_data['profit']);
            //物流商
            $shippingInfo = Cache::store('shipping')->getShipping(param($_data, 'shipping_id', 0));
            $_data['carrier'] = '';
            if ($shippingInfo) {
                $carrier = Cache::store('carrier')->getCarrier(param($shippingInfo, 'carrier_id', 0));
                $_data['carrier'] = $carrier['fullname'] ?? '';
            }

            //判断是否分摊转运费
            $_data['belong_type'] = $this->getParameter($_data, 'belong_type', 0);
            if ($_data['belong_type'] == 1) {
                //查询当前包裹id，对应的所有订单
                $orderIds = OrderDetailModel::where(['package_id' => ['in', $packageIds]])->group('order_id')
                    ->column('order_id');
                //所有订单的支付金额
                $count_order_amount = Order::where(['id' => ['in', $orderIds]])->sum('pay_fee');
                $proportion = $_data['order_amount'] / $count_order_amount;
                $_data['trans_shipping_fee'] = $_data['trans_shipping_fee'] * $proportion;
            }
            //货币转换率（总售价-渠道成交费-PayPal费用）×0.025
            $_data['currency_transform_fee'] = sprintf('%.2f', ($_data['order_amount_CNY'] - $_data['channel_cost_CNY'] - $_data['mc_fee']) * 0.025);
            if ($type == 2 && count($shipping_name) > 1 && count($packageIds) > 1) { //导出多品的情况
                $_data['shipping_name'] = '-';
                $_data['shipping_number'] = '-';
                $_data['package_number'] = '-';
                $_data['process_code'] = '-';
                $data[] = $_data;
                foreach ($shipping_name as $k => $value) {
                    $package_data = $this->initFieldValue($_data['channel_id'], $_data);
                    $package_data['shipping_name'] = $shipping_name[$k];
                    $package_data['shipping_number'] = $shipping_number[$k];
                    $package_data['package_number'] = $package_number[$k];
                    $package_data['package_fee'] = $package_fee[$k];
                    $package_data['shipping_fee'] = $shipping_fee[$k];
                    $package_data['process_code'] = $process_code[$k];
                    $package_data['shipping_time'] = $shipping_time[$k] ? date('Y-m-d H:i:s', $shipping_time[$k]) : '未发货';
                    $detail = $this->getDetail($k);
                    $package_data['goods_cost'] = sprintf("%.4f", $detail['cost']);
                    $package_data['sku_count'] = $detail['quantity'];
                    $package_data['warehouse_type'] = isset($warehouse_type[$k]) ? $warehouse_type[$k] : '';
                    $package_data['warehouse_name'] = isset($warehouse_name[$k]) ? $warehouse_name[$k] : '';
                    $data[] = $package_data;
                    unset($package_data);
                }
            } else {
                $shipping_name = array_diff($shipping_name, ['']);
                $shipping_number = array_diff($shipping_number, ['']);
                $package_number = array_diff($package_number, ['']);
                $process_code = array_diff($process_code, ['']);
                $_data['shipping_name'] = implode(',', $shipping_name);
                $_data['shipping_number'] = implode(',', $shipping_number);
                $_data['package_number'] = implode(',', $package_number);
                $_data['process_code'] = implode(',', $process_code);
                $data[] = $_data;
            }
            if (!empty($title)) {
                foreach ($data as $key => $value) {
                    $temp = [];
                    foreach ($title as $k => $v) {
                        $temp[$v] = $value[$v];
                    }
                    $info[] = $temp;
                }
                unset($data);
            }
            unset($_data);
        }
        if (!empty($title)) {
            return $info;
        } else {
            return $data;
        }
    }

    private function getWarehouseType($type)
    {
        switch ($type) {
            case 1:
                $warehouse_type = '本地仓';
                break;
            case 2:
                $warehouse_type = '海外仓';
                break;
            case 3:
                $warehouse_type = '4px';
                break;
            case 4:
                $warehouse_type = 'winit';
                break;
            case 5:
                $warehouse_type = 'fba';
                break;
            default;
                $warehouse_type = '';
        }

        return $warehouse_type;

    }

    /**
     * 获取 warehouseInfo
     * @param $warehouse_id
     * @return array
     * @throws Exception
     */
    private function warehouseInfo($warehouse_id)
    {
        $warehouse = Cache::store('warehouse')->getWarehouse($warehouse_id);
        if (isset($warehouse['type']) && !empty($warehouse['type'])) {
            $warehouseType = $this->getWarehouseType($warehouse['type']);
        } else {
            $warehouseType = "";
        }
        if (isset($warehouse['name']) && !empty($warehouse['name'])) {
            $warehouseName = $warehouse['name'];
        } else {
            $warehouseName = "";
        }
        return [$warehouseType, $warehouseName];
    }

    /**
     * 获取包裹成本
     * @param $packageId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getDetail($packageId)
    {
        $cost = 0;
        $quantity = 0;
        $orderDetailModel = new OrderDetailModel();
        $orderDetail = $orderDetailModel->where('package_id', strval($packageId))->field('sku_quantity, sku_cost')->select();
        foreach ($orderDetail as $item) {
            $cost += $item['sku_quantity'] * $item['sku_cost'];
            $quantity += $item['sku_quantity'];
        }
        return ['cost' => sprintf('%.2f', $cost), 'quantity' => $quantity];
    }

    /**
     * 获取销售员组长以及主管
     * @return array
     */
    public function getLeaderDirector($userId)
    {
        $data = [];
        $departmentUserMapService = new DepartmentUserMapService();
        $department_ids = $departmentUserMapService->getDepartmentByUserId($userId);
        $director_id = [];
        $leader_id = [];
        foreach ($department_ids as $d => $department) {
            if (!empty($department)) {
                $_leader_id = $departmentUserMapService->getGroupLeaderByChannel($department);
                if (!empty($_leader_id)) {
                    foreach ($_leader_id as $id) {
                        array_push($leader_id, $id);
                    }
                }
                $_director_id = $departmentUserMapService->getDirector($department);
                if (!empty($_director_id)) {
                    foreach ($_director_id as $id) {
                        array_push($director_id, $id);
                    }
                }
            }
        }
        $leader = [];
        foreach ($leader_id as $v) {
            $realname = cache::store('user')->getOneUserRealname($v);
            array_push($leader, $realname);
        }
        $director = [];
        foreach ($director_id as $v) {
            $realname = cache::store('user')->getOneUserRealname($v);
            array_push($director, $realname);
        }
        $data['team_leader_name'] = !empty($leader) ? implode(', ', $leader) : '';
        $data['supervisor_name'] = !empty($director) ? implode(', ', $director) : '';
        return $data;
    }

    /**
     * @return int|string
     */
    public function searchCount(array $condition = [])
    {
        $orderModel = new Order;
        $orderModel->join('`order_detail` detail', '`detail`.`order_id` = `order`.`id`', 'left');
        $orderModel->join('`order_package` package', '`package`.`id` = `detail`.`package_id`', 'left');
        return $orderModel->where($condition)->group('order.id')->count();
    }

    /**
     * 获取fba订单数量
     * @param $params
     * @return int|string
     * @throws Exception
     */
    public function fbaCount(array $condition = [])
    {
        $fbaModel = new FbaOrder();
        return $fbaModel->where($condition)->count();
    }

    /**
     * 获取查询条件
     * @param $params
     * @return array
     * @throws Exception
     */
    protected function getSearchCondition($params)
    {
        date_default_timezone_set("PRC");
        $condition = [];
        $channelId = $this->getParameter($params, 'channel_id', '');
        if (!$channelId) {
            throw new Exception('未设置查询平台id', 400);
        }
        $condition['order.channel_account'] = ['between', [$channelId * OrderType::ChannelVirtual, ($channelId + 1) * OrderType::ChannelVirtual]];
        $condition['package.channel_account'] = ['between', [$channelId * OrderType::ChannelVirtual, ($channelId + 1) * OrderType::ChannelVirtual]];
        $siteCode = $this->getParameter($params, 'site_code', '');
        if ($siteCode) $condition['order.site_code'] = $siteCode;
        $acctId = $this->getParameter($params, 'account_id', '');
        if ($acctId) {
            $condition['order.channel_account'] = $channelId * OrderType::ChannelVirtual + $acctId;
        }
        $acctCode = $this->getParameter($params, 'account_code', '');
        if ($acctCode) {
            $condition['account.code'] = $acctCode;
        }
        $sellerName = $this->getParameter($params, 'seller_name', '');
        if ($sellerName) {
            $condition['order.seller'] = ['like', $sellerName . '%'];
        }
        $order_number = $this->getParameter($params, 'order_number', '');
        if ($order_number) {
            $condition['order.order_number'] = $order_number;
        }
        $wareHouseId = $this->getParameter($params, 'warehouse_id', '');
        if ($wareHouseId) {
            $condition['package.warehouse_id'] = $wareHouseId;
        }
        $seller_id = $this->getParameter($params, 'seller_id', '');
        if ($seller_id) {
            $condition['order.seller_id'] = $seller_id;
        }
        $timeField = $this->getParameter($params, 'time_field', '');
        $timeStart = $this->getParameter($params, 'time_start', '');
        $timeEnd = $this->getParameter($params, 'time_end', '');
        if (!Validate::dateFormat($timeStart, 'Y-m-d') || !Validate::dateFormat($timeEnd, 'Y-m-d')) {
            throw new Exception('必须设置起止时间(如:2017-01-01)', 400);
        }
        $timeStart = strtotime($timeStart);
        $timeEnd = strtotime($timeEnd) + (3600 * 24 - 1);
        if ($timeStart > $timeEnd) {
            throw new Exception('开始时间不能大于结束时间', 400);
        }
        if ($timeField) {
            switch ($timeField) {
                case 'shipping_time':
                    $condition['package.shipping_time'] = ['between', [$timeStart, $timeEnd]];
                    break;
                case 'pay_time':
                    $condition['order.pay_time'] = ['between', [$timeStart, $timeEnd]];
                    break;
                default:
                    throw new Exception('不支持的查询时间字段', 400);
            }
        } else {
            throw new Exception('查询时间字段未设置', 400);
        }
        return $condition;
    }

    /**
     * fab条件查询
     * @param $params
     * @return array
     * @throws Exception
     */
    protected function fbaSearchCondition(&$params)
    {
        date_default_timezone_set("PRC");
        $condition = [];
        $siteCode = $this->getParameter($params, 'site_code', '');
        if ($siteCode) $condition['site'] = $siteCode;

        $order_number = $this->getParameter($params, 'order_number', '');
        if ($order_number) {
            $condition['order_number'] = $order_number;
        }
        $wareHouseId = $this->getParameter($params, 'warehouse_id', '');
        if ($wareHouseId) {
            $condition['warehouse_id'] = $wareHouseId;
        }
        $seller_id = $this->getParameter($params, 'seller_id', '');
        if ($seller_id) {
            $condition['seller_id'] = $seller_id;
        }
        $timeField = $this->getParameter($params, 'time_field', '');
        $timeStart = $this->getParameter($params, 'time_start', '');
        $timeEnd = $this->getParameter($params, 'time_end', '');
        if (!Validate::dateFormat($timeStart, 'Y-m-d') || !Validate::dateFormat($timeEnd, 'Y-m-d')) {
            throw new Exception('必须设置起止时间(如:2017-01-01)', 400);
        }
        $timeStart = strtotime($timeStart);
        $timeEnd = strtotime($timeEnd) + (3600 * 24 - 1);
        if ($timeStart > $timeEnd) {
            throw new Exception('开始时间不能大于结束时间', 400);
        }
        if ($timeField) {
            switch ($timeField) {
                case 'shipping_time':
                    $condition['shipping_time'] = ['between', [$timeStart, $timeEnd]];
                    break;
                case 'pay_time':
                    $condition['pay_time'] = ['between', [$timeStart, $timeEnd]];
                    break;
                default:
                    throw new Exception('不支持的查询时间字段', 400);
            }
        } else {
            throw new Exception('查询时间字段未设置', 400);
        }
        return $condition;
    }

    /**
     * 获取数据
     * @param $condition
     * @param int $page
     * @param int $pageSize
     * @param string $timeField
     * @param string $timeSort
     * @param int $channel_id
     * @return false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function doSearch($condition, $page = 1, $pageSize = 10, $timeField = '', $timeSort = 'ASC', $channel_id = 0)
    {
        $order = '';
        switch ($timeField) {
            case 'shipping_time':
                $order = 'package.shipping_time ' . $timeSort . ', order.id desc';
                break;
            case 'pay_time':
                $order = 'order.pay_time ' . $timeSort;
                break;
        }
        $order = $order ? $order . ', order.id desc ' : $order;
        $orderModel = new Order;

//        if(isset($params['warehouse_id']) && $params['warehouse_id']){
        $orderModel->join('`order_detail` detail', '`detail`.`order_id` = `order`.`id`', 'left');
        $orderModel->join('`order_package` package', '`package`.`id` = `detail`.`package_id`', 'left');
//        }

        $fields = '`order`.`id`,' .                 //系统单号
            '`order`.`channel_id`,' .                  //系统单号
            'sum(`detail`.`sku_quantity`) as sku_count,' .                  //系统单号
            '`order`.`channel_account_id`,' .          //平台账号id
            '`order`.`order_number`,' .                  //系统单号
            '`order`.`country_code`,' .                    // 国家
            '`order`.`site_code`,' .                     //站点编码
            '`order`.`seller_id`,' .                     //销售员id
            '`order`.`seller` AS seller_name,' .         //销售员姓名
            '`order`.`channel_order_number`,' .          //平台单号
            '`order`.`pay_time`,' .                      //支付时间
            '`order`.`type`,' .                      //0-渠道  1-手工 2-刷单
            '`order`.`belong_type`,' .                      //0-独立单 1-合并订单 2-多包裹订单 3-无需发货
            'order.cost,' .                              //商品成本
            '`package`.`shipping_time`,' .           //发货时间
            '`package`.`shipping_id`,' .           //货运方式id
            '`order`.`pay_fee` as order_amount ,' .                  //原币售价（支付金额）
            '`order`.`channel_cost`,' .                  //原币平台手续费
            '`order`.`pay_fee`,' .                  //支付金额
            '`order`.`currency_code`,' .                 //当前币种
            '`order`.`rate`,' .                          //当前汇率
            //	'(`order`.`pay_fee` * `order`.`rate`) AS order_amount_CNY,' .   //售价人民币（总支付费用）
            //	'(`order`.`channel_cost` * `order`.`rate`) AS channel_cost_CNY,' . //平台费用人民币
            '(`order`.`first_fee`+`order`.`tariff`) AS first_fee,' . //头程费
//            '`addr`.`country_code`,'.     //订单地址国家编码
//            '`addr`.`zipcode`,'.          //订单地址邮编
            '`order`.`paypal_fee` as mc_fee,' .           //paypal费用
            '`order`.`estimated_fee`';          //估计运费

        //获取需要邮编的平台
        $zipChannelList = $this->zipChannelList();
        if (in_array($channel_id, $zipChannelList)) {
            $orderModel->join('`order_address` addr', '`addr`.`order_id` = `order`.`id`', 'left');
            $fields .= ',`addr`.`country_code`, `addr`.`zipcode`';         //订单地址邮编
        }
        return $orderModel->field($fields)
            ->where($condition)
            ->order($order)
            ->group('order.id')
            ->page($page, $pageSize)
            ->select();
    }

    /**
     * 需要有邮编的平台
     */
    public function zipChannelList()
    {
        $wishChannel = ChannelAccountConst::channel_wish;
        $priceMinisterChannel = ChannelAccountConst::channel_PM;
        $fummartChannel = ChannelAccountConst::channel_Fummart;
        $darazChannel = ChannelAccountConst::channel_Daraz;
        $channelList = [$wishChannel, $priceMinisterChannel, $fummartChannel, $darazChannel];

        return $channelList;
    }

    /**
     * 需要货品总数的平台
     */
    public function skuChannelList()
    {
        $priceMinisterChannel = ChannelAccountConst::channel_PM;
        $fummartChannel = ChannelAccountConst::channel_Fummart;
        $darazChannel = ChannelAccountConst::channel_Daraz;
        $channelList = [$priceMinisterChannel, $fummartChannel, $darazChannel];

        return $channelList;
    }

    /**
     * fba订单搜索
     * @param $params
     * @param int $page
     * @param int $pageSize
     * @param string $timeField
     * @param string $timeSort
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function fbaSearch($condition, $page = 1, $pageSize = 10, $timeField = '', $timeSort = 'ASC')
    {
        $fbaModel = new FbaOrder();
        $order = '';
        switch ($timeField) {
            case 'shipping_time':
                $order = 'shipping_time ' . $timeSort . ', id desc';
                break;
            case 'pay_time':
                $order = 'pay_time ' . $timeSort;
                break;
        }
        $order = $order ? $order . ', id desc ' : $order;

        $field = 'id,order_number,channel_order_number,site,payment_method,currency_code,rate,order_amount,warehouse_id,
					channel_id,seller_id,channel_cost,channel_account_id,cost,first_fee,pay_time,pay_fee,transport_id,channel_shipping_free,
					order_status,create_time,sales_channel,order_time';

        $fbaModel->field($field)
            ->where($condition)
            ->order($order);

        $fbaData = $fbaModel->page($page, $pageSize)->select();
        foreach ($fbaData as $key => $item) {
            $fbaData[$key]['site_code'] = $item['site'];
            $fbaData[$key]['rate'] = sprintf("%.4f", $item['rate']);
            $fbaData[$key]['is_fba'] = ChannelAccountConst::channel_fba;
            unset($item['site']);
        }
        return $fbaData;
    }

    /**
     * 获取订单货品
     * @param int $order_id
     * @return array
     */
    function getOrderSkus($order_id = 0)
    {
        $skus = OrderDetailModel::field('sku,sku_id,sku_quantity')->where(['order_id' => $order_id])->select();
        if ($skus) {
            foreach ($skus as $key => $vo) {
                $ware_result = WarehouseGoodsModel::field('per_cost')->where(['sku_id' => $vo['sku_id']])->find();
                $skus[$key]['cost'] = $ware_result['per_cost'] ? $ware_result['per_cost'] : 0;
            }

        }
        return $skus;
    }

    /**
     * 获取fba订单货品
     * @param int $order_id
     * @return array
     */
    function getFbaOrderSkus($order_id = 0)
    {
        $skus = FbaOrderDetail::field('sku,sku_id,sku_quantity')->where(['fba_order_id' => $order_id])->select();
        if ($skus) {
            foreach ($skus as $key => $vo) {
                $ware_result = WarehouseGoodsModel::field('per_cost')->where(['sku_id' => $vo['sku_id']])->find();
                $skus[$key]['cost'] = $ware_result['per_cost'] ? $ware_result['per_cost'] : 0;
            }

        }
        return $skus;
    }

}