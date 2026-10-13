<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Floor;
use App\Models\MenuItem;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
   
    public function run(): void
    {
      
        $password = env('SEED_DEFAULT_PASSWORD');

        if (! $password) {
            if (app()->environment(['local', 'testing'])) {
                $password = 'password';
            } else {
                $password = Str::password(16, symbols: false);
                $this->command?->warn('تم توليد كلمة سر عشوائية للمستخدمين التجريبيين: ' . $password);
                $this->command?->warn('احفظها الآن - ما رح تظهر مرة تانية. غيّرها أو احذف الحسابات التجريبية قبل الإطلاق.');
            }
        }

       
        User::updateOrCreate(
            ['email' => 'admin@restaurant.com'],
            [
                'name' => 'أحمد المدير',
                'password' => $password,
                'role' => 'admin',
                'phone' => '0931234567',
                'is_active' => true,
              
                'salary_type' => 'monthly',
                'monthly_salary' => 2500000,
            ]
        );

        User::updateOrCreate(
            ['email' => 'cashier@restaurant.com'],
            [
                'name' => 'سارة الكاشير',
                'password' => $password,
                'role' => 'cashier',
                'phone' => '0937654321',
                'is_active' => true,
              
                'salary_type' => 'hourly',
                'hourly_rate' => 12000,
            ]
        );

        User::updateOrCreate(
            ['email' => 'driver@restaurant.com'],
            [
                'name' => 'خالد السائق',
                'password' => $password,
                'role' => 'driver',
                'phone' => '0939998887',
                'is_active' => true,
                'salary_type' => 'hourly',
                'hourly_rate' => 10000,
            ]
        );

        $menu = [
            'مقبلات' => [
                ['name' => 'حمص بالطحينة', 'price' => 15000, 'description' => 'حمص طازج مع زيت زيتون وصنوبر'],
                ['name' => 'متبل باذنجان', 'price' => 15000, 'description' => 'باذنجان مشوي مع طحينة وثوم'],
                ['name' => 'تبولة', 'price' => 15000, 'description' => 'بقدونس، برغل، طماطم، ليمون'],
                ['name' => 'ورق عنب', 'price' => 20000, 'description' => 'ورق عنب محشي أرز وخضار'],
            ],
            'المشاوي' => [
                ['name' => 'شيش طاووق', 'price' => 45000, 'description' => 'صدر دجاج متبل مشوي على الفحم'],
                ['name' => 'كباب لحمة', 'price' => 50000, 'description' => 'لحمة مفرومة متبلة مشوية'],
                ['name' => 'ريش غنم', 'price' => 65000, 'description' => 'ريش غنم طازجة مشوية'],
                ['name' => 'مشاوي مشكلة', 'price' => 75000, 'description' => 'تشكيلة من أطيب أنواع المشاوي'],
            ],
            'الأطباق الرئيسية' => [
                ['name' => 'كبة حلبية', 'price' => 55000, 'description' => 'كبة محشوة لحمة ومكسرات بصوص الكرز الحلبي'],
                ['name' => 'مقلوبة دجاج', 'price' => 45000, 'description' => 'أرز، دجاج، باذنجان، وقرنبيط'],
                ['name' => 'ورق عنب باللحمة', 'price' => 40000, 'description' => 'ورق عنب محشي أرز ولحمة'],
            ],
            'المشروبات' => [
                ['name' => 'عصير ليمون بالنعناع', 'price' => 8000, 'description' => null],
                ['name' => 'شاي أحمر', 'price' => 5000, 'description' => null],
                ['name' => 'قهوة عربية', 'price' => 7000, 'description' => null],
                ['name' => 'مياه غازية', 'price' => 5000, 'description' => null],
            ],
            'الحلويات' => [
                ['name' => 'كنافة بالجبنة', 'price' => 25000, 'description' => 'كنافة جبنة بالقطر'],
                ['name' => 'مهلبية', 'price' => 20000, 'description' => 'حليب، نشا، وماء ورد'],
                ['name' => 'بقلاوة', 'price' => 20000, 'description' => 'عجين رقيق بالفستق والقطر'],
            ],
        ];

     
        $categorySlugs = [
            'مقبلات' => 'appetizers',
            'المشاوي' => 'grills',
            'الأطباق الرئيسية' => 'main-dishes',
            'المشروبات' => 'drinks',
            'الحلويات' => 'desserts',
        ];

    
        $categoryTranslationsEn = [
            'مقبلات' => 'Appetizers',
            'المشاوي' => 'Grills',
            'الأطباق الرئيسية' => 'Main Dishes',
            'المشروبات' => 'Drinks',
            'الحلويات' => 'Desserts',
        ];

        $sort = 1;
        foreach ($menu as $categoryName => $items) {
            $slug = $categorySlugs[$categoryName] ?? 'category-' . $sort;

            $category = Category::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $categoryName,
                    'translations' => ['en' => ['name' => $categoryTranslationsEn[$categoryName] ?? $categoryName]],
                    'is_active' => true,
                    'sort_order' => $sort++,
               
                    'tax_rate' => $categoryName === 'المشروبات' ? 0.20 : null,
                ]
            );

            $itemSort = 1;
            foreach ($items as $item) {
                MenuItem::updateOrCreate(
                    ['category_id' => $category->id, 'name' => $item['name']],
                    [
                        'description' => $item['description'],
                        'price' => $item['price'],
                        'is_available' => true,
                        'sort_order' => $itemSort++,
                       
                        'track_stock' => $categoryName === 'المشاوي',
                        'stock_quantity' => $categoryName === 'المشاوي' ? rand(3, 25) : 0,
                        'low_stock_threshold' => $categoryName === 'المشاوي' ? 5 : 5,
                    ]
                );
            }
        }

    
        $shishTawook = MenuItem::where('name', 'شيش طاووق')->first();
        if ($shishTawook) {
        
            $shishTawook->update([
                'translations' => [
                    'en' => [
                        'name' => 'Shish Tawook',
                        'description' => 'Grilled marinated chicken breast skewers',
                    ],
                ],
            ]);

            $sizeGroup = \App\Models\ModifierGroup::updateOrCreate(
                ['menu_item_id' => $shishTawook->id, 'name' => 'الحجم'],
                ['is_required' => true, 'max_selections' => 1, 'sort_order' => 1]
            );
            \App\Models\Modifier::updateOrCreate(['modifier_group_id' => $sizeGroup->id, 'name' => 'وسط'], ['extra_price' => 0]);
            \App\Models\Modifier::updateOrCreate(['modifier_group_id' => $sizeGroup->id, 'name' => 'كبير'], ['extra_price' => 10000]);

            $extrasGroup = \App\Models\ModifierGroup::updateOrCreate(
                ['menu_item_id' => $shishTawook->id, 'name' => 'إضافات'],
                ['is_required' => false, 'max_selections' => 3, 'sort_order' => 2]
            );
            \App\Models\Modifier::updateOrCreate(['modifier_group_id' => $extrasGroup->id, 'name' => 'جبنة إضافية'], ['extra_price' => 5000]);
            \App\Models\Modifier::updateOrCreate(['modifier_group_id' => $extrasGroup->id, 'name' => 'صوص ثوم'], ['extra_price' => 2000]);
        }

    
        $groundFloor = Floor::updateOrCreate(
            ['name' => 'الطابق الأرضي'],
            ['canvas_width' => 900, 'canvas_height' => 600, 'sort_order' => 1, 'is_active' => true]
        );
        $terrace = Floor::updateOrCreate(
            ['name' => 'التراس'],
            ['canvas_width' => 900, 'canvas_height' => 400, 'sort_order' => 2, 'is_active' => true]
        );

   
        $statuses = ['available', 'available', 'available', 'occupied', 'reserved'];
        $columns = 4;
        for ($i = 1; $i <= 10; $i++) {
            $onGroundFloor = $i <= 7;
            $index = $onGroundFloor ? $i - 1 : $i - 8;
            $col = $index % $columns;
            $row = intdiv($index, $columns);

            RestaurantTable::updateOrCreate(
                ['table_number' => 'T' . $i],
                [
                    'capacity' => [2, 4, 4, 6, 8][array_rand([2, 4, 4, 6, 8])],
                    'status' => $statuses[array_rand($statuses)],
                    'floor_id' => $onGroundFloor ? $groundFloor->id : $terrace->id,
                    'pos_x' => 60 + $col * 150,
                    'pos_y' => 60 + $row * 150,
                    'width' => 80,
                    'height' => 80,
                    'shape' => $i % 3 === 0 ? 'circle' : 'rectangle',
                ]
            );
        }

   
        Currency::updateOrCreate(
            ['code' => 'SYP'],
            ['name' => 'ليرة سورية', 'symbol' => 'ل.س', 'exchange_rate' => 1, 'is_default' => true, 'is_active' => true]
        );
        Currency::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'دولار أمريكي', 'symbol' => '$', 'exchange_rate' => 0.00007, 'is_default' => false, 'is_active' => true]
        );
        Currency::updateOrCreate(
            ['code' => 'EUR'],
            ['name' => 'يورو', 'symbol' => '€', 'exchange_rate' => 0.000065, 'is_default' => false, 'is_active' => true]
        );

   
        $customers = [
            ['name' => 'محمد العلي', 'phone' => '0931112223', 'email' => 'mohammad@example.com', 'loyalty_points' => 150],
            ['name' => 'رنا خالد', 'phone' => '0932223334', 'email' => 'rana@example.com', 'loyalty_points' => 40],
            ['name' => 'يوسف حسن', 'phone' => '0933334445', 'email' => null, 'loyalty_points' => 0],
        ];
        foreach ($customers as $c) {
            Customer::updateOrCreate(['phone' => $c['phone']], $c);
        }

    
        Coupon::updateOrCreate(['code' => 'WELCOME10'], [
            'type' => 'percentage', 'value' => 10, 'is_active' => true, 'expires_at' => now()->addMonths(3),
        ]);
        Coupon::updateOrCreate(['code' => 'SAVE5'], [
            'type' => 'fixed', 'value' => 15000, 'is_active' => true, 'expires_at' => now()->addMonths(3),
        ]);

      
        $settings = [
            'restaurant_name' => 'مطعم زيتون',
            'phone' => '0930000000',
            'address' => 'شارع الحمرا، دمشق، سوريا',
            'currency' => 'ل.س',
            'tax_rate' => '0.16',
          
            'loyalty_points_per_currency' => '0.001',
         
            'loyalty_point_value' => '200',
           
            'delivery_fee' => '7000',
           
            'shift_max_hours' => '12',
        ];
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
