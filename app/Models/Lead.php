<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    /** @use HasFactory<\Database\Factories\LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id','name','email','firstname','lastname','job_title','phone',
        'city','link','item_id','sams_pay','sams_manage','sams_platform',
        'sams_pay_client_management','booked_demo','comments'
    ];

    public function user() { return $this->belongsTo(User::class); }
}
