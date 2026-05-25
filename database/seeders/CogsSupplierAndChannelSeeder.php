<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CogsSupplier;
use App\Models\CogsChannel;

class CogsSupplierAndChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $suppliers = [
            [
                'id'   => 'SUP-DHL',
                'name' => 'DHL Express Inbound',
            ],
            [
                'id'   => 'SUP-JNE',
                'name' => 'JNE Logistik Utama',
            ],
            [
                'id'   => 'SUP-FXI',
                'name' => 'FedEx Cargo Indonesia',
            ],
        ];

        foreach ($suppliers as $supplierData) {
            CogsSupplier::updateOrCreate(
                ['id' => $supplierData['id']], 
                ['name' => $supplierData['name']]
            );
        }

        
        $channels = [
            [
                'id'          => 'CH-DHL-REG',
                'name'        => 'DHL Regular Sea Freight',
                'supplier_id' => 'SUP-DHL',
                'type'        => 'percentage',
                'amount'      => 5.50, 
            ],
            [
                'id'          => 'CH-DHL-AIR',
                'name'        => 'DHL Priority Air Cargo',
                'supplier_id' => 'SUP-DHL',
                'type'        => 'unit',
                'amount'      => 25000.00, 
            ],
            [
                'id'          => 'CH-JNE-REG',
                'name'        => 'JNE Trucking Regional',
                'supplier_id' => 'SUP-JNE',
                'type'        => 'percentage',
                'amount'      => 3.00, 
            ],
            [
                'id'          => 'CH-JNE-CTC',
                'name'        => 'JNE City Courier Flat',
                'supplier_id' => 'SUP-JNE',
                'type'        => 'unit',
                'amount'      => 12000.00, 
            ],
            [
                'id'          => 'CH-FEDEX-INT',
                'name'        => 'FedEx International Bulk',
                'supplier_id' => 'SUP-FXI',
                'type'        => 'percentage',
                'amount'      => 7.50,
            ],
        ];

        foreach ($channels as $channelData) {
            CogsChannel::updateOrCreate(
                ['id' => $channelData['id']], 
                [
                    'name'        => $channelData['name'],
                    'supplier_id' => $channelData['supplier_id'],
                    'type'        => $channelData['type'],
                    'amount'      => $channelData['amount'],
                ]
            );
        }
    }
}