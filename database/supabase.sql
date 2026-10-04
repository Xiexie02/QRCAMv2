create extension if not exists pgcrypto;

create table if not exists public.students (
    id uuid primary key,
    student_no text not null unique,
    full_name text not null,
    class_section text not null default '',
    qr_token text not null unique,
    created_at timestamptz not null
);

create table if not exists public.attendance (
    id uuid primary key,
    student_id uuid not null references public.students(id),
    attendance_date date not null,
    attended_at timestamptz not null,
    status text not null default 'present' check (status in ('present', 'absent', 'excused')),
    note text not null default '',
    unique (student_id, attendance_date)
);

create table if not exists public.audit_logs (
    id uuid primary key,
    actor text not null,
    action text not null,
    record_id uuid not null,
    before_data jsonb,
    after_data jsonb not null,
    created_at timestamptz not null
);

create index if not exists attendance_date_idx on public.attendance (attendance_date);
create index if not exists audit_record_created_idx on public.audit_logs (record_id, created_at);

alter table public.students enable row level security;
alter table public.attendance enable row level security;
alter table public.audit_logs enable row level security;

create or replace function public.adjust_attendance(
    p_actor text,
    p_student_id uuid,
    p_attendance_date date,
    p_status text,
    p_note text,
    p_attendance_id uuid,
    p_attended_at timestamptz,
    p_audit_id uuid,
    p_audit_created_at timestamptz
)
returns jsonb
language plpgsql
security definer
set search_path = public, pg_temp
as $$
declare
    v_before jsonb;
    v_after jsonb;
begin
    if p_status not in ('present', 'absent', 'excused') or length(p_note) > 500 then
        raise exception 'Invalid attendance status or note.';
    end if;

    select to_jsonb(a) into v_before
    from public.attendance as a
    where a.student_id = p_student_id and a.attendance_date = p_attendance_date
    for update;

    if found then
        update public.attendance as a
        set status = p_status, note = p_note
        where a.id = (v_before ->> 'id')::uuid
        returning to_jsonb(a) into v_after;
    else
        insert into public.attendance (id, student_id, attendance_date, attended_at, status, note)
        values (p_attendance_id, p_student_id, p_attendance_date, p_attended_at, p_status, p_note)
        returning to_jsonb(attendance) into v_after;
    end if;

    insert into public.audit_logs (id, actor, action, record_id, before_data, after_data, created_at)
    values (p_audit_id, p_actor, 'attendance_adjusted', (v_after ->> 'id')::uuid, v_before, v_after, p_audit_created_at);

    return jsonb_build_object('before', v_before, 'after', v_after);
end;
$$;

revoke all on function public.adjust_attendance(text, uuid, date, text, text, uuid, timestamptz, uuid, timestamptz) from public;
grant execute on function public.adjust_attendance(text, uuid, date, text, text, uuid, timestamptz, uuid, timestamptz) to service_role;
