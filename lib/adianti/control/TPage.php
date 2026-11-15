<?php
namespace Adianti\Control;

abstract class TPage
{
    protected $children = [];

    // Método que deve ser sobrescrito pelos Controllers para processar POST/GET antes do output HTML
    public function process()
    {
        // default empty
    }

    public function add($child)
    {
        $this->children[] = $child;
    }

    public function show()
    {
        foreach ($this->children as $child) {
            if (is_string($child)) {
                echo $child;
            } elseif (is_object($child) && method_exists($child, 'show')) {
                $child->show();
            }
        }
    }
}
