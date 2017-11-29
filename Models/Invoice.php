<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;
use App;
use DateTimeImmutable;
use DateTime;
use PDF;
use Phoenix\EloquentMeta\MetaTrait;

class Invoice extends Model implements HasMediaConversions
{
    use HasMediaTrait;
    use MetaTrait;
    
    protected $table = 'invoices';

    public function registerMediaConversions()  
    {
    }

    public function getTotalProductsSaldo($products, $format = null) 
    {
        $price = 0;

        $productsArray = array();

        if ($products != null) {
            if (isset(json_decode($products, true)[0])) {
                $productsArray = json_decode($products, true);
            } else {
                array_push($productsArray, json_decode($products, true));
            }

            foreach ($productsArray as $product) {
                if (isset($product['amount']) && isset($product['price'])) {
                    if (isset($product['tax'])) {
                        $price += $product['amount'] * $product['price'] * ($product['tax'] / 100 + 1);
                    } else {
                        $price += $product['amount'] * $product['price'];
                    }
                }
            }
        }

        return $format != null ? $price : '&euro;'.number_format($price, 2, '.', '');
    }

    public function getTotalProductsSaldoExclTax($products, $format = null) 
    {
        $price = 0;

        $productsArray = array();

        if ($products != null) {
            if (isset(json_decode($products, true)[0])) {
                $productsArray = json_decode($products, true);
            } else {
                array_push($productsArray, json_decode($products, true));
            }

            foreach ($productsArray as $product) {
                if (isset($product['amount']) && isset($product['price'])) {
                    $price += $product['amount'] * $product['price'];
                }
            }
        }

        return $format != null ? $price : '&euro;'.number_format($price, 2, '.', '');
    }

    public function getTotalProductsTax($products, $format = null) 
    {
        $price = 0;

        $productsArray = array();

        if ($products != null) {
            if (isset(json_decode($products, true)[0])) {
                $productsArray = json_decode($products, true);
            } else {
                array_push($productsArray, json_decode($products, true));
            }

            foreach ($productsArray as $product) {
                if (isset($product['tax'])) {
                    $price = $product['tax'];
                }
            }
        }

        return $format != null ? $price : '&euro;'.number_format($price, 2, '.', '');
    }

    public function getInvoice($options, $view) 
    {
        $html = view('template.pdf.invoice', $options)->render();

        $pdf = App::make('Vsmoraes\Pdf\Pdf');
        $pdf = $pdf
            ->load($html)
            ->filename('factuur-'.$options['invoiceNumber'].''.($options['debitCredit'] == '-credit' ? 'credit' : '').'.pdf')
        ;

        switch ($view) {
            case 'show':
                $pdf = $pdf->show();
                break;
                
            case 'download':
                $pdf = $pdf->download();
                break;
            
            case 'output':
                $pdf = $pdf->output();
                break;
        }

        return $pdf;
    }

}
