@php
  $name ??= $id ?? null;
  $id ??= $name ?? null;
  $value ??= false;
  $class ??= null;

  $trueLabel ??= "Yes";
  $falseLabel ??= "No";

  $includeSelect ??= isset($name);
@endphp
<select name="{{$name}}" id="{{$id}}" class="{{$class ?? "form-select"}}">
    <option value="1" @if($value) selected @endif>{{ $trueLabel }}</option>
    <option value="0" @if(!$value) selected @endif>{{ $falseLabel }}</option>
</select>