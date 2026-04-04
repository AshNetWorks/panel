<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2SubscribeUnbanLogTable extends Migration
{
    public function up()
    {
        Schema::create('v2_subscribe_unban_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'idx_user_created');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_subscribe_unban_log');
    }
}
