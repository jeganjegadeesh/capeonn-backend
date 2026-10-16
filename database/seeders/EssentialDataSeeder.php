<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the essential, non-demo lookup and operational data required
 * to launch the Capeonn platform:
 * 1. Statutory / Standard Leave Types (CL, SL, PL, LOP)
 * 2. Primary Office Location for Geofence Attendance Tracking
 * 3. Official Public / National Holidays for the current calendar year
 *
 * This seeder contains ZERO dummy employees, ZERO fake projects, ZERO tasks,
 * and ZERO fake chat/attendance history.
 */
class EssentialDataSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', config('capeonn.company.code'))->first()
            ?? Company::firstOrFail();

        $this->seedLeaveTypes($company);
        $this->seedOfficeLocation($company);
        $this->seedHolidays($company);
    }

    /**
     * Seeds statutory standard leave types for the organization.
     */
    private function seedLeaveTypes(Company $company): void
    {
        $types = [
            [
                'name'        => 'Casual Leave',
                'code'        => 'CL',
                'annual_days' => 12,
                'is_paid'     => true,
            ],
            [
                'name'        => 'Sick Leave',
                'code'        => 'SL',
                'annual_days' => 10,
                'is_paid'     => true,
            ],
            [
                'name'        => 'Privilege Leave',
                'code'        => 'PL',
                'annual_days' => 15,
                'is_paid'     => true,
            ],
            [
                'name'        => 'Loss of Pay',
                'code'        => 'LOP',
                'annual_days' => 0,
                'is_paid'     => false,
            ],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $type['code']],
                [
                    'name'        => $type['name'],
                    'annual_days' => $type['annual_days'],
                    'is_paid'     => $type['is_paid'],
                    'is_active'   => true,
                ],
            );
        }
    }

    /**
     * Seeds the primary office location for geofenced attendance tracking.
     */
    private function seedOfficeLocation(Company $company): void
    {
        OfficeLocation::firstOrCreate(
            ['company_id' => $company->id, 'name' => config('capeonn.office.name', 'Headquarters')],
            [
                'address'       => config('capeonn.office.address', 'Main Office Campus'),
                'latitude'      => (float) config('capeonn.office.latitude', 12.9716),
                'longitude'     => (float) config('capeonn.office.longitude', 77.5946),
                'radius_meters' => (int) config('capeonn.office.radius_meters', 500),
                'is_active'     => true,
            ],
        );
    }

    /**
     * Seeds standard public / national holidays for the current launch year.
     */
    private function seedHolidays(Company $company): void
    {
        $year = Carbon::now()->year;

        $holidays = [
            ['name' => "New Year's Day",        'date' => "{$year}-01-01", 'holiday_type' => 'national'],
            ['name' => 'Republic Day',          'date' => "{$year}-01-26", 'holiday_type' => 'national'],
            ['name' => 'Labour Day',            'date' => "{$year}-05-01", 'holiday_type' => 'national'],
            ['name' => 'Independence Day',      'date' => "{$year}-08-15", 'holiday_type' => 'national'],
            ['name' => 'Gandhi Jayanti',        'date' => "{$year}-10-02", 'holiday_type' => 'national'],
            ['name' => 'Diwali / Deepavali',    'date' => "{$year}-11-08", 'holiday_type' => 'festival'],
            ['name' => 'Christmas',             'date' => "{$year}-12-25", 'holiday_type' => 'festival'],
        ];

        foreach ($holidays as $h) {
            $existing = Holiday::where('company_id', $company->id)
                ->whereDate('date', $h['date'])
                ->first();

            if (! $existing) {
                Holiday::create([
                    'company_id'   => $company->id,
                    'name'         => $h['name'],
                    'date'         => $h['date'],
                    'holiday_type' => $h['holiday_type'],
                    'is_optional'  => false,
                ]);
            }
        }
    }
}
