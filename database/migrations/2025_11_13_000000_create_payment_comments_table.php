<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_comments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('user_id');

            $table->text('body'); // nội dung bình luận

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('payment_id')
                ->references('id')->on('payments')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_comments');
    }
};
