<?php
namespace App\Modules\Shell\Infrastructure\Models;
use Illuminate\Database\Eloquent\Model;
final class UserUiPreference extends Model
{
    protected $fillable = ['user_id', 'namespace', 'value'];
    protected function casts(): array { return ['value' => 'array']; }
}
