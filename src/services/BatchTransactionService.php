<?php
/**
 * Commerce Reports plugin for Craft CMS 3.x
 *
 * Plugin to run specific Commerce reports
 *
 * @link      https://milesherndon.com
 * @copyright Copyright (c) 2019 MilesHerndon
 */

namespace milesherndon\commercereports\services;

use milesherndon\commercereports\CommerceReports;
use milesherndon\commercereports\helpers\ReportDateTimeHelper;
use milesherndon\commercereports\helpers\ReportFileHelper;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;

/**
 * @author    MilesHerndon
 * @package   CommerceReports
 * @since     1.1.0
 */
class BatchTransactionService extends Component
{
    // Private Properties
    // =========================================================================

    private $accountCodes = [
        'pay' => [
            'desc'=>'PAY',
            'acct#'=>'01-00-00-1007-000'
        ],
        'ar/pp' => [
            'desc'=>'AR, PP',
            'acct#'=>'01-00-00-1054-000'
        ],
        'inventory' => [
            'desc'=>'INV',
            'acct#'=>'01-00-00-1073-000'
        ],
        'shipping' => [
            'desc'=>'Freight_#, Handling_#',
            'acct#'=>'01-02-00-8220-000'
        ],
        'product' => [
            'desc'=>'TR',
            'acct#'=>'01-02-00-4135-000'
        ],
        'tax' => [
            'desc'=>'TAX/IN',
            'acct#'=>'01-02-00-8026-000'
        ],
        'cogs' => [
            'desc'=>'COGS',
            'acct#'=>'01-02-00-8240-000'
        ],
    ];

    private $dates;

    private $datesName;

    private $datesReport;

    // Public Methods
    // =========================================================================

    /**
     * Create batch transaction zip file.
     *
     * @param $request
     * @return string
     */
    public function batchTransactions($request)
    {
        $datesInSeconds = ReportDateTimeHelper::formatTimes($request, 'U', true);

        $startDateInSeconds = (int)$datesInSeconds['start'];
        $endDateInSeconds = (int)$datesInSeconds['end'];
        $incrementer = 86400;
        $dateCounter = $startDateInSeconds;

        $files = [];
        $tempPath = ReportFileHelper::getStoragePath('commerce-reports-batch');

        $orderSpreadsheet = CommerceReports::$plugin->orderService->getOrdersWithDetails($request, $tempPath);
        array_push($files, $orderSpreadsheet);

        $refundsSpreadsheet = CommerceReports::$plugin->orderService->getOrdersWithDetails($request, $tempPath, true);
        array_push($files, $refundsSpreadsheet);

        for ($dateCounter = $startDateInSeconds; $dateCounter < $endDateInSeconds; $dateCounter += $incrementer) {
            $params = [
                0 => [
                    'value' => $dateCounter
                ],
                1 => [
                    'value' => $dateCounter + $incrementer
                ]
            ];

            $this->dates = ReportDateTimeHelper::formatTimes($params, 'Y-m-d H:i');
            $this->datesName = ReportDateTimeHelper::formatTimes($params, 'Y-m-d');
            $this->datesReport = ReportDateTimeHelper::formatTimes($params, 'mdY');

            $fileName = $tempPath . '/' . date('Y-m-d', strtotime("+1 day", strtotime($this->datesName['start'])));

            $orders = Order::find()
                ->isCompleted(true)
                ->orderBy('dateOrdered asc')
                ->dateOrdered(["and", ">= ".$this->dates['start'], "< ".$this->dates['end']])
                ->all();

            $files = $this->createDailyOrderFiles($orders, $files, $fileName);

            $refundedOrders = Order::find()
                ->isCompleted(true)
                ->orderBy('dateUpdated asc')
                ->orderStatus('refunded')
                ->dateUpdated(["and", ">= ".$this->dates['start'], "< ".$this->dates['end']])
                ->all();

            if (!empty($refundedOrders)) {
                $files = $this->createDailyOrderFiles($refundedOrders, $files, $fileName, 1);
            }
        }

        $zip = ReportFileHelper::generateZip($files, $request);

        return $zip;
    }

    public function createDailyOrderFiles($orders, $files, $fileName, $refunds=0)
    {
        if ($refunds) {
            $fileName = $fileName.'-refunds';
        }

        array_push($files, $fileName.'.txt');

        // NOTE: CSV
        $csvFileName = $fileName.'.csv';

        $fp = fopen($csvFileName, 'w');

        fputcsv($fp, [
            'Batch Number',
            'Account Number',
            'Post Date',
            'Type',
            'Journal',
            'Journal Reference',
            'Amount'
        ]);

        // $keys = array('shipping', 'ar/pp', 'inventory', 'product', 'cogs', 'pay', 'tax');
        $keys = array('shipping', 'inventory', 'product', 'cogs', 'pay', 'tax');
        $initialTemplateArray = array_fill_keys($keys, 0);

        foreach ($orders as $order) {
            if ($refunds) {
                $initialTemplateArray['shipping'] += floatval($order->getAdjustmentsTotalByType("shipping"));
                $initialTemplateArray['inventory'] += floatval(CommerceReports::$plugin->inventoryService->totalProductWholesale($order->getLineItems()));
                $initialTemplateArray['product'] += floatval($order->itemTotal - $order->getAdjustmentsTotalByType("tax"));
                $initialTemplateArray['cogs'] += floatval(CommerceReports::$plugin->inventoryService->totalProductWholesale($order->getLineItems())) * -1;
                $initialTemplateArray['pay'] += floatval($order->itemTotal + $order->getAdjustmentsTotalByType("shipping")) * -1;
                $initialTemplateArray['tax'] += floatval($order->getAdjustmentsTotalByType("tax"));
            } else {
                $initialTemplateArray['shipping'] += floatval($order->getAdjustmentsTotalByType("shipping")) * -1;
                // $initialTemplateArray['ar/pp'] += floatval($order->totalPaid);
                $initialTemplateArray['inventory'] += floatval(CommerceReports::$plugin->inventoryService->totalProductWholesale($order->getLineItems())) * -1;
                $initialTemplateArray['product'] += floatval($order->itemTotal - $order->getAdjustmentsTotalByType("tax")) * -1;
                $initialTemplateArray['cogs'] += floatval(CommerceReports::$plugin->inventoryService->totalProductWholesale($order->getLineItems()));
                $initialTemplateArray['pay'] += floatval($order->itemTotal + $order->getAdjustmentsTotalByType("shipping"));
                $initialTemplateArray['tax'] += floatval($order->getAdjustmentsTotalByType("tax")) * -1;
            }
        }

        $initialTemplateArray = array_map(
            function($value){
                return number_format((float)$value, 2, '.', '');
            }, $initialTemplateArray);

        $fileContentsString = '';
        $fileContentsString .= 'DIV=04 SEP=|\r\n';
        $fileContentsString .= '1|CRAFT|'.$this->datesReport['end'].'|CRAFT|CRAFT IMPORT'."|\r\n";

        foreach ($initialTemplateArray as $key => $rawvalue) {
            $fileContentsString .= '4|'.$this->accountCodes[$key]['acct#'].'|'.$this->accountCodes[$key]['desc'].'||CRAFT||'.(string)$rawvalue."|\r\n";

            $value = abs($rawvalue);

            fputcsv($fp, [
                '',
                $this->accountCodes[$key]['acct#'],
                $this->datesName['start'], ($rawvalue > 0 || ($refunds && $rawvalue == 0)  ? 'D' : 'C'),
                'Craft Journal',
                $this->accountCodes[$key]['desc'],
                (string)$value
            ]);

        }

        $file = file_put_contents($fileName.".txt", $fileContentsString.PHP_EOL , LOCK_EX);

        // if ($refunds) {
        //     Craft::dd($fileContentsString);
        // }

        fclose($fp);
        array_push($files, $csvFileName);

        return $files;
    }
}
