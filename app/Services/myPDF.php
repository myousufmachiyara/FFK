<?php

namespace App\Services;

use TCPDF;

class myPDF extends TCPDF
{

   
    private $tableHtml = ''; // Variable to store the HTML for the table

    // Set table HTML
    public function setTableHtml($html) {
       $this->tableHtml = $html;
    }

    public function Header()
    {
        $this->SetFont('helvetica','B', 10);
        $this->Cell(0, 10, '', 0, 1, 'C');
        if ($this->getPage() > 1) {
            $this->SetMargins(10, 17, 10); // 10mm left, 50mm top, 10mm right            
            $this->setCellPadding(1.2); // Set padding for all cells in the table
            $this->writeHTML($this->tableHtml, true, false, true, false, '');
        }
    }



    public function Footer()
    {
        $this->SetY(-15);
    
        // Set font for the rest of the footer
        $this->SetFont('helvetica', 'I', 13);
        $this->SetTextColor(23, 54, 93);
    
        // Get the page width
        $pageWidth = $this->getPageWidth();
        
        // Content and positions
        $companyName = 'Memon Fabrication And Installation';
        $websiteLink = 'mfi.com.pk';
        $pageNumber = 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages();
    
        // Calculate text widths
        $leftTextWidth = $this->GetStringWidth($websiteLink);
        $centerTextWidth = $this->GetStringWidth($companyName);
        $rightTextWidth = $this->GetStringWidth($pageNumber);
    
        // Calculate positions
        $leftX = 10; // Distance from left
        $centerX = ($pageWidth - $centerTextWidth) / 2; // Center position
        $rightX = $pageWidth - 34; // Distance from right
    
        // Draw the border
        $this->SetLineWidth(1);
        $this->SetDrawColor(23, 54, 93); // Black color
        $this->Line(10, $this->getY(), $pageWidth - 10, $this->getY()); // Top border of the footer
    
        // Company website on the left
        $this->SetFont('helvetica', 'B', 13);
        $this->SetX($leftX);
        $this->Cell($leftTextWidth, 10, $websiteLink, 0, 0, 'L');
    
        // Company name in the center with increased boldness
        $this->SetFont('helvetica', 'B', 13); // Increase font size for extra bold effect
        $this->SetX($centerX);
        $this->Cell($centerTextWidth, 10, $companyName, 0, 0, 'C');
    
        // Page number on the right
        $this->SetFont('helvetica', 'B', 13); // Revert to italic for page number
        $this->SetX($rightX);
        $this->Cell($rightTextWidth, 10, $pageNumber, 0, 0, 'R');
    }
    
    
    
    function convertCurrencyToWords($number) {
        $Thousand = 1000;
        $Million = $Thousand * $Thousand;
        $Billion = $Thousand * $Million;
        $Trillion = $Thousand * $Billion;
    
        if ($number == 0) {
            return "AED Zero Only";
        }
    
        $isNegative = $number < 0;
        $number = abs($number);
    
        $result = "";
    
        // Trillions
        if ($number >= $Trillion) {
            $result .= $this->convertDigitGroup(floor($number / $Trillion)) . " Trillion ";
            $number %= $Trillion;
        }
    
        // Billions
        if ($number >= $Billion) {
            $result .= $this->convertDigitGroup(floor($number / $Billion)) . " Billion ";
            $number %= $Billion;
        }
    
        // Millions
        if ($number >= $Million) {
            $result .= $this->convertDigitGroup(floor($number / $Million)) . " Million ";
            $number %= $Million;
        }
    
        // Thousands
        if ($number >= $Thousand) {
            $result .= $this->convertDigitGroup(floor($number / $Thousand)) . " Thousand ";
            $number %= $Thousand;
        }
    
        // Hundreds and below
        if ($number > 0) {
            $result .= $this->convertDigitGroup($number);
        }
    
        $result = "AED " .trim($result) . " Only";
    
        return $isNegative ? "Negative " . $result : $result;
    }
    
    function convertDigitGroup($number) {
        $hundreds = floor($number / 100);
        $remainder = $number % 100;
        $result = "";
    
        if ($number == 1) {  // Special case for "One"
            return "One";
        }
        if ($number == 2) {  // Special case for "One"
            return "Two";
        }
        if ($number == 3) {  // Special case for "One"
            return "Three";
        }
        if ($number == 4) {  // Special case for "One"
            return "Four";
        }
        if ($number == 5) {  // Special case for "One"
            return "Five";
        }
        if ($number == 6) {  // Special case for "One"
            return "Six";
        }
        if ($number == 7) {  // Special case for "One"
            return "Seven";
        }
        if ($number == 8) {  // Special case for "One"
            return "Eight";
        }
        if ($number == 9) {  // Special case for "One"
            return "Nine";
        }
    
        if ($hundreds > 0) {
            $result .= $this->convertSingleDigit($hundreds) . " Hundred ";
        }
    
        if ($remainder > 0) {
            if ($remainder < 20) {
                $result .= $this->convertTens($remainder);
            } else {
                $result .= $this->convertTens(floor($remainder / 10) * 10);
                if ($remainder % 10 > 0) {
                    $result .= "-" . $this->convertSingleDigit($remainder % 10);
                }
            }
        }
    
        return trim($result);
    }
    
    function convertSingleDigit($digit) {
        $digits = [
            0 => "",
            1 => "One",
            2 => "Two",
            3 => "Three",
            4 => "Four",
            5 => "Five",
            6 => "Six",
            7 => "Seven",
            8 => "Eight",
            9 => "Nine"
        ];
    
        return $digits[$digit];
    }
    
    function convertTens($number) {
        $tens = [
            10 => "Ten",
            11 => "Eleven",
            12 => "Twelve",
            13 => "Thirteen",
            14 => "Fourteen",
            15 => "Fifteen",
            16 => "Sixteen",
            17 => "Seventeen",
            18 => "Eighteen",
            19 => "Nineteen",
            20 => "Twenty",
            30 => "Thirty",
            40 => "Forty",
            50 => "Fifty",
            60 => "Sixty",
            70 => "Seventy",
            80 => "Eighty",
            90 => "Ninety"
        ];
    
        return $tens[$number] ?? "";
    }
}