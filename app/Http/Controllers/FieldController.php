<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

use App\Events\FieldChangeEvent;
use App\Events\FieldDeleteEvent;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Customization\Eloquent\Field;
use App\Customization\Eloquent\Screen;
use App\Project\Provider;

class FieldController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($project_key)
    {
        $fields = Provider::getFieldList($project_key);
        foreach ($fields as $key => $field)
        {
            $fields[$key]->screens = Screen::whereRaw([ 'field_ids' => $field->id ])->get(['name']);
        }
        $types = Provider::getTypeList($project_key, ['name']);
        return Response()->json(['ecode' => 0, 'data' => $fields, 'options' => [ 'types' => $types ]]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $project_key)
    {
        $name = $request->input('name');
        if (!$name || trim($name) == '')
        {
            throw new \UnexpectedValueException('the name cannot be empty.', -10002);
        }

        $key = $request->input('key');
        if (!$key || trim($key) == '')
        {
            throw new \InvalidArgumentException('field key cannot be empty.', -10002);
        }
        if (in_array($key, [ 'id', 'type', 'reporter', 'created_at', 'updated_at', 'no', 'page', 'orderBy' ]))
        {
            throw new \InvalidArgumentException('field key has been used by system.', -10002);
        }
        if (Provider::isFieldKeyExisted($project_key, $key))
        {
            throw new \InvalidArgumentException('field key cannot be repeated.', -10002);
        }

        $type = $request->input('type');
        if (!$type)
        {
            throw new \UnexpectedValueException('the type cannot be empty.', -10002);
        }

        $allTypes = [ 'Tags', 'Number', 'Text', 'TextArea', 'Select', 'MultiSelect', 'RadioGroup', 'CheckboxGroup', 'DatePicker', 'DateTimePicker', 'TimeTracking', 'File', 'SingleVersion', 'MultiVersion', 'Url' ];
        if (!in_array($type, $allTypes))
        {
            throw new \UnexpectedValueException('the type is incorrect type.', -10002);
        }

        $optionTypes = [ 'Select', 'MultiSelect', 'RadioGroup', 'CheckboxGroup' ];
        if (in_array($type, $optionTypes))
        {
            $optionValues = $request->input('optionValues') ?: [];
            $defaultValue = $request->input('defaultValue') ?: '';
            if ($defaultValue)
            {
                $defaults = explode(',', $defaultValue);
                $options = array_column($optionValues, 'id');
                $defaultValue = implode(',', array_intersect($defaults, $options));
            }
            $field = Field::create([ 'project_key' => $project_key, 'optionValues' => $optionValues, 'defaultValue' => $defaultValue ] + $request->all());
        }
        else
        {
            $field = Field::create([ 'project_key' => $project_key ] + $request->all());
        }
        return Response()->json(['ecode' => 0, 'data' => $field]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($project_key, $id)
    {
        $field = Field::find($id);
        //if (!$field || $project_key != $field->project_key)
        //{
        //    throw new \UnexpectedValueException('the field does not exist or is not in the project.', -10002);
        //}
        // get related screen
        $field->screens = Screen::whereRaw([ 'field_ids' => $id ])->get(['name']);

        return Response()->json(['ecode' => 0, 'data' => $field]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $project_key, $id)
    {
        $name = $request->input('name');
        if (isset($name))
        {
            if (!$name || trim($name) == '')
            {
                throw new \UnexpectedValueException('the name can not be empty.', -10002);
            }
        }
        $field = Field::find($id);
        if (!$field || $project_key != $field->project_key)
        {
            throw new \UnexpectedValueException('the field does not exist or is not in the project.', -10002);
        }

        $optionTypes = [ 'Select', 'MultiSelect', 'RadioGroup', 'CheckboxGroup' ];
        if (in_array($field->type, $optionTypes))
        {
            $optionValues = $request->input('optionValues');
            $defaultValue = $request->input('defaultValue');
            if (isset($optionValues) || isset($defaultValue))
            {
                $optionValues = isset($optionValues) ? $optionValues : ($field->optionValues ?: []);
                $options = array_column($optionValues, 'id');
                $defaultValue = isset($defaultValue) ? $defaultValue : ($field->defaultValue ?: '');
                $defaults = explode(',', $defaultValue);
                $defaultValue = implode(',', array_intersect($defaults, $options));

                $field->fill([ 'optionValues' => $optionValues, 'defaultValue' => $defaultValue ] + $request->except(['project_key', 'key', 'type']))->save();
            }
        }

        $field->fill($request->except(['project_key', 'key', 'type']))->save();

        Event::fire(new FieldChangeEvent($id));

        return Response()->json(['ecode' => 0, 'data' => Field::find($id)]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($project_key, $id)
    {
        $field = Field::find($id);
        if (!$field || $project_key != $field->project_key)
        {
            throw new \UnexpectedValueException('the field does not exist or is not in the project.', -10002);
        }
        Field::destroy($id);
        Event::fire(new FieldDeleteEvent($id));
        return Response()->json(['ecode' => 0, 'data' => ['id' => $id]]);
    }
}
