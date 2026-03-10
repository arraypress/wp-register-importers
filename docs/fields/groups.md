# Field Groups

Fields can be visually grouped in the mapping UI using the `group` key. Groups appear as labelled separators in the
field mapping step.

```php
'fields' => [
    'sku'    => [ 'label' => 'SKU',          'group' => 'Identification', 'required' => true ],
    'name'   => [ 'label' => 'Product Name', 'group' => 'Identification', 'required' => true ],
    'price'  => [ 'label' => 'Price',        'group' => 'Pricing',        'type' => 'number' ],
    'currency' => [ 'label' => 'Currency',   'group' => 'Pricing',        'type' => 'currency' ],
    'stock'  => [ 'label' => 'Stock',        'group' => 'Inventory',      'type' => 'integer' ],
],
```

Groups are purely visual — they affect how fields are displayed in the mapping step but have no effect on processing or
validation. Fields without a `group` are displayed without a separator.
