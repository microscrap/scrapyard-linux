<?php

use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIMode;
use GeneralPurposeIO\SPI\SPIBusSettings;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionDriver;
use Microscrap\ScrapyardLinux\SPI\PosixSPIConnectionFactory;

it('hands a worker the word size with the rest of the bus settings, and takes it back', function () {
    $settings = (new PosixSPIConnectionFactory(0, new PosixSPIConnectionDriver))
        ->mode(SPIMode::MODE_3)->speed(2_000_000)->endianness(SPIEndianness::LSB)->bitsPerByte(9)
        ->settings();
    $worker = (new PosixSPIConnectionFactory(0, new PosixSPIConnectionDriver))->configure($settings);

    expect($settings)->toEqual(new SPIBusSettings(SPIMode::MODE_3, 2_000_000, SPIEndianness::LSB, 9))
        ->and($worker->settings())->toEqual($settings);
});
