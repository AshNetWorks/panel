<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateV2ServerLogTable extends Migration
{
    public function up()
    {
        Schema::create('v2_server_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->integer('server_id');
            $table->string('server_type', 32)->default('');
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->decimal('rate', 10, 2)->default(1.00);
            $table->integer('log_at');
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);

            $table->unique(['user_id', 'server_id', 'log_at'], 'uniq_node_day');
            $table->index(['user_id', 'log_at'], 'idx_user_log');
        });
    }

    public function down()
    {
        Schema::dropIfExists('v2_server_log');
    }
}
