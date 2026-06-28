<?php

test('home page shows footer content', function () {
    $this->get('/')
        ->assertSuccessful()
        ->assertSee(__('main.footer_weekly_deals'))
        ->assertSee(__('main.footer_fast_delivery'))
        ->assertSee('light_en_logo.png', false)
        ->assertSee('dark_en_logo.png', false);
});

test('footer brand logo switches with locale', function () {
    $this->withSession(['locale' => 'ar'])
        ->get('/')
        ->assertSuccessful()
        ->assertSee('light_ar_logo.png', false)
        ->assertSee('dark_ar_logo.png', false);
});
