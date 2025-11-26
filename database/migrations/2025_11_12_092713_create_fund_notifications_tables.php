<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_comments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('user_id');

            $table->text('body'); // nội dung bình luận

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            // FK
            $table->foreign('expense_id')
                ->references('id')
                ->on('expenses')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index('expense_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_comments');
    }
};
