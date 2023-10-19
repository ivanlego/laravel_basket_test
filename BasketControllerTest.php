<?php

namespace Tests\Feature\Http\Controllers\V1;

use App\Facades\Buyer;
use App\Models\Basket;
use App\Models\User;
use App\Services\Store\Basket\BasketService;
use App\Services\Store\DeliveryScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Http\Controllers\V1\Traits\Authenticate;
use Tests\Feature\Http\Controllers\V1\Traits\Store;
use Tests\TestCase;

class BasketControllerTest extends TestCase
{
    use Authenticate;
    use RefreshDatabase;
    use WithFaker;
    use Store;

    /**
     * @var User
     */
    private $user;

    private string $now;

    public function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->now = Carbon::now()->format('Y-m-d');
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::getBasket
     */
    public function test_should_return_basket()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $this->makeBasket();

        $buyerToken = $this->getBuyerToken();
        $nearestDate = DeliveryScheduleService::getNearestDate(["saturday", "wednesday"]);

        $this
            ->asBuyer()
            ->getJson('/api/v1/basket')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json
                    ->where('token', $buyerToken)
                    ->where('total', $this->totalPrice)
                    ->where('total_prev', $this->totalPriceWithoutDiscount)
                    ->has("delivery_baskets.0", fn (AssertableJson $json) =>
                        $json
                            ->where('total', 2362.5)
                            ->where('total_prev', 2557.5)
                            ->where("nearest_date", $this->now)
                            ->where('delivery_price', 500)
                            ->has("products", 2)
                            ->has("products.0", fn (AssertableJson $json) =>
                                $json
                                    ->where('id', $this->weightProduct->id)
                                    ->where('price', 100)
                                    ->missing('price_discount')
                                    ->where('price_unit', '100 гр')
                                    ->where('count', 3)
                                    ->where('sum', 900)
                                    ->missing('sum_prev')
                                    ->where('sum_unit', '900 гр')
                                    ->etc())
                            ->has("products.1", fn (AssertableJson $json) =>
                                $json
                                    ->where('id', $this->discountProduct->id)
                                    ->where('price', 850)
                                    ->where('price_discount', 750)
                                    ->where('count', 3)
                                    ->where('sum', 1462.5)
                                    ->where('sum_prev', 1657.5)
                                    ->where('sum_unit', "1.95 кг")
                                    ->etc())
                            ->etc())
                    ->has("delivery_baskets.1.products", 2));
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::addProduct
     */
    public function test_should_add_product_in_basket()
    {
        $product = $this->makeDiscountProduct();
        $this->makeDeliveryType(true);

        $response = $this
            ->asBuyer()
            ->postJson('/api/v1/basket/add', [
                'product_id' => $product->id
            ]);

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->has('token')
                    ->where('total', 750 * $product->weight)
                    ->where('total_prev', 850 * $product->weight)
                    ->has('delivery_baskets')
                    ->has("delivery_baskets.0.products", 1)
                    ->has("delivery_baskets.0.products.0", fn (AssertableJson $json) =>
                        $json->where('id', $product->id)
                            ->where('title', $product->title)
                            ->where('slug', $product->slug)
                            ->where('price', 850)
                            ->where('price_discount', 750)
                            ->where('count', 1)
                            ->where('sum', 750 * $product->weight)
                            ->where('sum_prev', 850 * $product->weight)
                            ->where('sum_unit', "$product->weight кг")
                            ->etc()));

        $token = Buyer::getBasketToken();

        $this->assertDatabaseHas('baskets', [
            'token' => $token
        ]);

        $basket = Basket::where('token', $token)->first();

        $this->assertDatabaseHas('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id,
            'count' => 1
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::addProduct
     */
    public function test_should_reject_product_without_delivery_schedule()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $product = $this->makeNoDeliveryProduct();
        $buyerToken = $this->getBuyerToken();

        $response = $this
            ->asBuyer()
            ->postJson('/api/v1/basket/add', [
                'product_id' => $product->id
            ]);

        $response
            ->assertOk()
            ->assertJson(
                fn (AssertableJson $json) =>
                    $json->where('token', $buyerToken)
                        ->where('total', 0)
                        ->etc()
            );

        $token = Buyer::getBasketToken();

        $this->assertDatabaseHas('baskets', ['token' => $token]);

        $basket = Basket::where('token', $token)->first();

        $this->assertDatabaseMissing('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::removeProduct
     */
    public function test_should_remove_product_from_basket()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $basket = $this->makeBasket();
        $product = $this->discountProduct;
        $buyerToken = $this->getBuyerToken();

        $total = (1000 * 0.3 * 3) + (900 * 2);

        $response = $this
            ->asBuyer()
            ->postJson('/api/v1/basket/remove', ['product_id' => $product->id]);

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('token', $buyerToken)
                    ->where('total', (int)$total)
                    ->has('delivery_baskets', 2)
                    ->has("delivery_baskets.0.products", 1)
                    ->has("delivery_baskets.1.products", 1)
                    ->etc());

        $this->assertDatabaseMissing('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::incrementProductCount
     */
    public function test_should_increment_product_count_in_basket()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $basket = $this->makeBasket();
        $product = $this->weightProduct;
        $buyerToken = $this->getBuyerToken();

        $response = $this
            ->asBuyer()
            ->postJson('/api/v1/basket/increment', ['product_id' => $product->id]);

        $sum = 1000 * $product->weight;

        $response
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('token', $buyerToken)
                    ->where('total', (int)($this->totalPrice + $sum))
                    ->has('delivery_baskets', 2)
                    ->has("delivery_baskets.0.products", 2)
                    ->etc());

        $this->assertDatabaseHas('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id,
            'count' => 4
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::decrementProductCount
     */
    public function test_should_decrement_product_count_in_basket()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $basket = $this->makeBasket();
        $product = $this->weightProduct;
        $buyerToken = $this->getBuyerToken();

        $sum = 1000 * $product->weight;

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/decrement', ['product_id' => $product->id])
            ->assertOk()
            ->assertJson(
                fn (AssertableJson $json) =>
                    $json->where('token', $buyerToken)
                        ->where('total', (int)($this->totalPrice - $sum))
                        ->where('total_prev', (int)($this->totalPriceWithoutDiscount - $sum))
                        ->has('delivery_baskets', 2)
            );

        $this->assertDatabaseHas('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id,
            'count' => 2
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::clearBasket
     */
    public function test_should_clear_basket()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');

        $basket = $this->makeBasket();
        $buyerToken = $this->getBuyerToken();

        $response = $this
            ->asBuyer()
            ->postJson('/api/v1/basket/clear');

        $response
            ->assertOk()
            ->assertJson(
                fn (AssertableJson $json) =>
                    $json->where('token', $buyerToken)
                        ->where('total', 0)
                        ->has('delivery_baskets', 0)
                        ->etc()
            );

        $this->assertDatabaseMissing('basket_product', [
            'basket_id' => $basket->id
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::clearBasket
     */
    public function test_should_clear_basket_by_delivery_date()
    {
        $this->makeBasket();
        $buyerToken = $this->getBuyerToken();
        $nearestDate = DeliveryScheduleService::getNearestDate(["saturday", "wednesday"]);

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/clear', [
                'delivery_basket' => $nearestDate
            ])
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('token', $buyerToken)
                    ->where('total', 2362.5)
                    ->has('delivery_baskets', 1)
                    ->has("delivery_baskets.0", fn (AssertableJson $json) =>
                        $json
                            ->where('total', 2362.5)
                            ->has('products', 2)
                            ->etc())
                    ->etc());
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::incrementProductCount
     */
    public function test_should_reject_incrementing_when_count_not_enough()
    {
        $basket = $this->makeBasket();
        $product = $this->makeProduct([], [
            'count' => 1,
            'delivery_schedule' => null
        ]);

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/add', ['product_id' => $product->id]);

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/increment', ['product_id' => $product->id])
            ->assertStatus(400)
            ->assertJson(fn (AssertableJson $json) =>
                $json->has('error')
                    ->etc());

        $this->assertDatabaseHas('basket_product', [
            'basket_id' => $basket->id,
            'product_id' => $product->id,
            'count' => 1
        ]);
    }

    /**
     * @covers \App\Http\Controllers\V1\BasketController::addProduct
     */
    public function test_should_make_basket_without_buyer_token()
    {
        $product = $this->makeDiscountProduct();

        $this
            ->postJson('/api/v1/basket/add', ['product_id' => $product->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('basket_product', [
            'product_id' => $product->id
        ]);
    }

    public function test_should_basket_split_product_by_preorder()
    {
        $productFirst = $this->makeByPreorderProduct(['date' => Carbon::now()->addDays(3)]);
        $productSecond = $this->makeByPreorderProduct(['date' => Carbon::now()->addDays(5)]);

        $buyerToken = $this->getBuyerToken();

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/add', ['product_id' => $productFirst->id])
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('token', $buyerToken)
                    ->has('delivery_baskets', 1)
                    ->has("delivery_baskets.0", fn (AssertableJson $json) =>
                        $json
                            ->where('by_preorder', true)
                            ->where('nearest_date', $productFirst->getNearestDeliveryDatesAttribute()[0])
                            ->has('products', 1)
                            ->etc())
                ->etc())
            ->assertStatus(200);

        $this
            ->asBuyer()
            ->postJson('/api/v1/basket/add', ['product_id' => $productSecond->id])
            ->assertJson(fn (AssertableJson $json) =>
                $json->where('token', $buyerToken)
                    ->has('delivery_baskets', 2)
                    ->has("delivery_baskets.0", fn (AssertableJson $json) =>
                        $json
                            ->where('by_preorder', true)
                            ->where('nearest_date', $productFirst->getNearestDeliveryDatesAttribute()[0])
                            ->has('products', 1)
                            ->etc())
                    ->has("delivery_baskets.1", fn (AssertableJson $json) =>
                        $json
                            ->where('by_preorder', true)
                            ->where('nearest_date', $productSecond->getNearestDeliveryDatesAttribute()[0])
                            ->has('products', 1)
                            ->etc())
                    ->etc()
                ->etc())
            ->assertStatus(200);

        $this   ->assertDatabaseHas('basket_product', ['product_id' => $productFirst->id])
                ->assertDatabaseHas('basket_product', ['product_id' => $productSecond->id]);
    }
}
