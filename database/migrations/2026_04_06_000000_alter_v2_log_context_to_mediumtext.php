<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2_log.context 原为 TEXT（64KB 上限），大型 SQL 错误信息会超限导致 insert 失败，
 * 改为 MEDIUMTEXT（16MB），根治日志丢失问题。
 */
class AlterV2LogContextToMediumtext extends Migration
{
    public function up()
    {
        Schema::table('v2_log', function (Blueprint $table) {
            $table->mediumText('context')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('v2_log', function (Blueprint $table) {
            $table->text('context')->nullable()->change();
        });
    }
}
