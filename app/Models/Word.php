<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Word extends Model
{
    use HasFactory;

    /**
     * Nome da tabela no banco de dados.
     *
     * @var string
     */
    protected $table = 'words';

    /**
     * Definir a chave primária (caso seja 'id' do tipo UUID).
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indica que a chave primária não é auto-incremento.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Define o tipo da chave primária (UUID -> string).
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Remove a coluna e as marcações de data de criação e atualização.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Atributos que podem ser atribuídos em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'word',
        'response',
        'rating',
    ];

    /**
     * Conversões de tipos para atributos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id'     => 'string',
        'rating' => 'integer',
    ];

    /**
     * Gera automaticamente um UUID para o campo id antes de criar o registro.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
