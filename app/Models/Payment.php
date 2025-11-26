<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\ClassMember;   // <-- Đúng namespace, có dấu \ đầy đủ

class Payment extends Model
{
    // Chọn 1 trong 2: fillable hoặc guarded (đừng dùng cả hai)
    // Cách dễ: mở toàn bộ
    protected $guarded = [];

    // hoặc giữ fillable rõ ràng:
    // protected $fillable = [
    //   'status','verified_by','verified_at','auto_verified',
    //   'verify_reason_code','verify_reason_detail',
    //   'ocr_raw','ocr_amount','ocr_txn_ref','ocr_method','ocr_confidence',
    //   'proof_path'
    // ];

    protected $casts = ['verified_at' => 'datetime'];

    public function invoice()  { return $this->belongsTo(Invoice::class); }

    public function payer()    {                       // <-- dùng ClassMember
        return $this->belongsTo(ClassMember::class,'payer_id');
    
    }
public function comments()
{
    return $this->hasMany(PaymentComment::class);
}

    public function verifier() { return $this->belongsTo(User::class,'verified_by'); }
}
