<?php

namespace Backpack\CRUD\Tests\Feature;

use Backpack\CRUD\Tests\config\CrudPanel\BaseDBCrudPanel;
use Backpack\CRUD\Tests\config\Http\Controllers\UploaderConfigurationCrudController;
use Backpack\CRUD\Tests\config\Models\Uploader;
use Backpack\CRUD\Tests\config\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Backpack\CRUD\Tests\config\HasUploadedFiles;

/**
 * @covers Backpack\CRUD\app\Library\Uploaders\Uploader
 * @covers Backpack\CRUD\app\Library\Uploaders\SingleFile
 * @covers Backpack\CRUD\app\Library\Uploaders\MultipleFiles
 * @covers Backpack\CRUD\app\Library\Uploaders\Support\RegisterUploadEvents
 * @covers Backpack\CRUD\app\Library\Uploaders\Support\UploadersRepository
 * @covers Backpack\CRUD\app\Library\Uploaders\Support\FileNameGenerator
 */
class UploadersConfigurationTest extends BaseDBCrudPanel
{
    use HasUploadedFiles;
    protected string $testBaseUrl;

    protected function defineRoutes($router)
    {
        $router->crud(config('backpack.base.route_prefix').'/uploader-configuration', UploaderConfigurationCrudController::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->testBaseUrl = config('backpack.base.route_prefix').'/uploader-configuration';
        Storage::fake('uploaders');
        $this->actingAs(User::find(1));
    }

    public function test_it_can_access_the_uploaders_create_page()
    {
        $response = $this->get($this->testBaseUrl.'/create');
        $response->assertStatus(200);
    }

    public function test_it_can_store_uploaded_files_using_our_file_name_generator()
    {
        $response = $this->post($this->testBaseUrl, [
            'upload' => $this->getUploadedFile('avatar1.jpg'),
            'upload_multiple' => $this->getUploadedFiles(['avatar2.jpg', 'avatar3.jpg']),
        ]);
        
        $response->assertStatus(302);
        
        $response->assertRedirect($this->testBaseUrl);

        $this->assertDatabaseCount('uploaders', 1);

        $files = Storage::disk('uploaders')->allFiles();

        $this->assertEquals(3, count($files));

        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/avatar\d{1}-[a-zA-Z0-9]{4}\.jpg/', $file);
        }

        // get the entry from database and also make sure the file names are stored correctly
        $entry = Uploader::first();
        $this->assertNotNull($entry);
        $this->assertMatchesRegularExpression('/avatar\d{1}-[a-zA-Z0-9]{4}\.jpg/', $entry->upload);
        $this->assertMatchesRegularExpression('/avatar\d{1}-[a-zA-Z0-9]{4}\.jpg/', $entry->upload_multiple[0]);
        $this->assertMatchesRegularExpression('/avatar\d{1}-[a-zA-Z0-9]{4}\.jpg/', $entry->upload_multiple[1]);
    }
}
