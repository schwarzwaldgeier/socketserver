<?php

namespace Schwarzwaldgeier\WetterSocket;

use PHPUnit\Framework\TestCase;

class RecordTest extends TestCase
{

    public function testCreateRecord(){
        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV71.6,");
        self::assertIsObject($record);
    }

    public function testCalculateWindDirectionOffset(){
        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV5.0,");
        self::assertEquals(345.0, $record->winddirection);

        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV355.0,");
        self::assertEquals(339.0, $record->winddirection);

    }

    public function testValidityCheck(){
        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV5.0,");
        self::assertEquals(true, $record->isValid());

        //missing temperature
        $record = new Record("22:51:02, 08.02.22, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV5.0,");
        self::assertEquals(false, $record->isValid());

        //direction > 360
        $record = new Record("22:51:02, 08.02.22, TE21.26, DR1046.95, FE32.07, WS16.34, WD30.25, WC3.09, WV370.0,");
        echo $record;
        self::assertEquals(false, $record->isValid());

    }

}
