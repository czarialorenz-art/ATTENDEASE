{{-- The student's own section, from their profile (FR-AUTH-15). --}}
@if (filled($student->section))
    <span class="badge-neutral font-semibold" title="Section">Section {{ $student->section }}</span>
@else
    <span class="badge border border-dashed border-slate-300 font-normal text-slate-400" title="The student hasn't set a section in their profile">No section</span>
@endif
