@extends('layouts.previewscreennextvite')

@section('title')
    {{ $screen->title ?? __('Web Entry') }}
@endsection

@section('content')
<div class="container" id="webentry">
    @if($completed)
        <div class="row">
            <div class="col-12">
                <div class="alert alert-success" role="alert">
                    {{ __('Your submission has been received.') }}
                </div>
                @if($completedUrl)
                    <a href="{{ $completedUrl }}" class="btn btn-primary">{{ __('Continue') }}</a>
                @endif
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-12 preview-item-title">
                {{ $screen->title ?? '' }}
            </div>
            <div class="col-12">
                <vue-form-renderer
                    ref="renderer"
                    v-model="formData"
                    :config="screen.config"
                    :computed="screen.computed"
                    :custom-css="screen.custom_css"
                    :watchers="screen.watchers"
                    :show-errors="true"
                    @submit="submitForm"
                />
                <div class="mt-3 mb-3">
                    <button type="button" class="btn btn-primary" :disabled="submitting" @click="submitForm">
                        {{ __('Submit') }}
                    </button>
                    <span v-if="submitting" class="text-muted ms-2">{{ __('Submitting...') }}</span>
                </div>
                <div v-if="error" class="alert alert-danger" role="alert">@{{ error }}</div>
            </div>
        </div>
    @endif
</div>
@endsection

@section('js')
    @foreach($manager->getScripts() as $script)
        <script defer src="{{ $script }}"></script>
    @endforeach
    <script>
    window.addEventListener('load', function () {
        if (window.Vue && window.ScreenBuilder && window.ScreenBuilder.VueFormRenderer) {
            window.Vue.component('vue-form-renderer', window.ScreenBuilder.VueFormRenderer);
        }

        new window.Vue({
            el: '#webentry',
            data: function () {
                return {
                    screen: @json($screen),
                    formData: @json($formData ?? (object) []),
                    submitting: false,
                    error: null
                };
            },
            methods: {
                submitForm: function () {
                    if (this.submitting) {
                        return;
                    }
                    this.submitting = true;
                    this.error = null;

                    var self = this;
                    var csrf = document.querySelector('meta[name="csrf-token"]');
                    var headers = {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    };
                    if (csrf) {
                        headers['X-CSRF-TOKEN'] = csrf.getAttribute('content');
                    }

                    fetch(@json($submitUrl), {
                        method: 'POST',
                        headers: headers,
                        credentials: 'same-origin',
                        body: JSON.stringify({ data: this.formData })
                    })
                        .then(function (response) {
                            return response.json().then(function (body) {
                                return { ok: response.ok, status: response.status, body: body };
                            });
                        })
                        .then(function (result) {
                            self.submitting = false;
                            if (result.ok) {
                                var redirect = result.body && result.body.redirect;
                                window.location.href = redirect || @json(route('package.webentry.show', [$process->id, $nodeId]) . '?completed=1');
                            } else {
                                self.error = (result.body && result.body.message)
                                    ? result.body.message
                                    : @json(__('Unable to submit the form.'));
                            }
                        })
                        .catch(function () {
                            self.submitting = false;
                            self.error = @json(__('Unable to submit the form.'));
                        });
                }
            }
        });
    });
    </script>
@endsection