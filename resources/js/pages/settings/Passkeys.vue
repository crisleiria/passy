<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/passkeys';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {browserSupportsWebAuthn, startRegistration} from "@simplewebauthn/browser";

const passKeyOptions = ref(null);

const passkey = ref(null);

interface Passkey {
    id: number;
    name: string;
    created_at: string;
}

defineProps<{
    passkeys: Passkey[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'PassKeys settings',
        href: edit().url,
    },
];

const form = useForm({
    name: '',
});

const deletePasskey = (passkey: Passkey) => {
    if (confirm('Are you sure you want to delete this passkey?')) {
        router.delete(`/settings/passkeys/${passkey.id}`);
    }
};

const registerPasskey = () => {

    if (!browserSupportsWebAuthn()) {
        alert('Your browser does not support WebAuthn.');
        return;
    }

    axios.get('/settings/passkeys/register')
        .then(async response => {
          if (response.data) {
            passKeyOptions.value = response.data
            startRegistration({
              optionsJSON : response.data,
              useAutoRegister : false
            }).then((response) => {
              console.log(response)
              passkey.value = response;
              createPasskey();
            })
            .catch(error => {
              console.log(error)
            });
          }
        })
        .catch(error => {
          console.log(error)
        });
}

const createPasskey = () => {

    /*
    form.post('/settings/passkeys', {
        onSuccess: () => form.reset(),
    });
    */

    axios.post('/settings/passkeys', {
    name: form.name,
    passkey: JSON.stringify(passkey.value)
  })
    .then(response => {
    if(response.data)
        form.reset()
    })
    .catch(error => {
    console.log(error)
    });

};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Passkeys settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Passkeys settings"
                    description="Update your account's passkey settings"
                />

                <form @submit.prevent="registerPasskey" class="flex items-end gap-4">
                    <div class="grid gap-2 flex-1">
                        <Label for="name">Passkey Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="block w-full"
                            placeholder="My MacBook Pro"
                            required
                        />
                    </div>
                    <Button type="submit" :disabled="form.processing || !form.name">
                        Add Passkey
                    </Button>
                </form>

                <div v-if="passkeys.length > 0" class="border rounded-lg divide-y">
                    <div v-for="passkey in passkeys" :key="passkey.id" class="p-4 flex items-center justify-between">
                        <div>
                            <div class="font-medium">{{ passkey.name }}</div>
                            <div class="text-sm text-gray-500">Created at: {{ new Date(passkey.created_at).toLocaleDateString() }}</div>
                        </div>
                        <button @click="deletePasskey(passkey)" class="text-red-500 hover:text-red-700 text-sm font-medium">
                            Delete
                        </button>
                    </div>
                </div>

                <div v-else class="text-gray-500 text-center py-4">
                    No passkeys registered.
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
