<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEmailChangedAtToV2User extends Migration
{
    public function up()
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedInteger('email_changed_at')->nullable()->after('email')->comment('上次修改邮箱的时间戳');
        });
    }

    public function down()
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('email_changed_at');
        });
    }
}
