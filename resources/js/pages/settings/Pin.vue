<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { ref } from 'vue';
import axios from 'axios';
import { LoaderCircle, Key, Shield, AlertTriangle } from 'lucide-vue-next';
import {
    deriveKeyFromPIN,
    unwrapMasterKey,
    wrapMasterKey,
    importKeyFromBase64,
    generateSalt,
    arrayBufferToBase64,
} from '@/lib/crypto';

const page = usePage();

// Current PIN verification
const currentPin = ref('');
const newPin = ref('');
const newPinConfirm = ref('');
const error = ref('');
const success = ref('');
const processing = ref(false);

// Master Key reset
const showMasterKeyReset = ref(false);
const masterKeyInput = ref('');
const masterKeyNewPin = ref('');
const masterKeyNewPinConfirm = ref('');
const masterKeyError = ref('');
const masterKeySuccess = ref('');
const masterKeyProcessing = ref(false);

// Change PIN with current PIN
const changePinWithCurrentPin = async () => {
    error.value = '';
    success.value = '';

    if (!currentPin.value) {
        error.value = 'Por favor insere o PIN atual';
        return;
    }
    if (newPin.value.length < 4) {
        error.value = 'O novo PIN deve ter pelo menos 4 caracteres';
        return;
    }
    if (newPin.value !== newPinConfirm.value) {
        error.value = 'Os novos PINs não coincidem';
        return;
    }

    processing.value = true;

    try {
        // 1. Get current encryption data
        const encDataRes = await axios.get('/api/vault/encryption-data');
        if (!encDataRes.data.hasEncryption) {
            error.value = 'Dados de encriptação não encontrados';
            processing.value = false;
            return;
        }

        const { encrypted_master_key, pin_salt } = encDataRes.data;

        // 2. Verify current PIN by unwrapping master key
        const currentUnwrapKey = await deriveKeyFromPIN(currentPin.value, pin_salt);
        const masterKey = await unwrapMasterKey(encrypted_master_key, currentUnwrapKey);

        // 3. Generate new salt and wrap master key with new PIN
        const newSalt = generateSalt();
        const newSaltBase64 = arrayBufferToBase64(newSalt);
        console.log('Old salt:', pin_salt);
        console.log('New salt:', newSaltBase64);
        
        const newUnwrapKey = await deriveKeyFromPIN(newPin.value, newSalt);
        const newWrappedMasterKey = await wrapMasterKey(masterKey, newUnwrapKey);
        
        console.log('Old encrypted_master_key:', encrypted_master_key);
        console.log('New encrypted_master_key:', newWrappedMasterKey);

        // 4. Update on server
        const response = await axios.post('/auth/webauthn/reset-pin', {
            email: page.props.auth.user.email,
            encrypted_master_key: newWrappedMasterKey,
            pin_salt: newSaltBase64,
        });
        
        console.log('Server response:', response.data);

        success.value = 'PIN alterado com sucesso!';
        currentPin.value = '';
        newPin.value = '';
        newPinConfirm.value = '';
        processing.value = false;

    } catch (err: any) {
        console.error('Change PIN error:', err);
        error.value = 'PIN atual incorreto';
        processing.value = false;
    }
};

// Change PIN with Master Key
const changePinWithMasterKey = async () => {
    masterKeyError.value = '';
    masterKeySuccess.value = '';

    if (!masterKeyInput.value) {
        masterKeyError.value = 'Por favor insere a Master Key';
        return;
    }
    if (masterKeyNewPin.value.length < 4) {
        masterKeyError.value = 'O novo PIN deve ter pelo menos 4 caracteres';
        return;
    }
    if (masterKeyNewPin.value !== masterKeyNewPinConfirm.value) {
        masterKeyError.value = 'Os novos PINs não coincidem';
        return;
    }

    masterKeyProcessing.value = true;

    try {
        // 1. Import master key to verify
        const masterKey = await importKeyFromBase64(masterKeyInput.value.trim());

        // 2. Generate new salt and wrap master key with new PIN
        const newSalt = generateSalt();
        const newSaltBase64 = arrayBufferToBase64(newSalt);
        const newUnwrapKey = await deriveKeyFromPIN(masterKeyNewPin.value, newSalt);
        const newWrappedMasterKey = await wrapMasterKey(masterKey, newUnwrapKey);

        // 3. Update on server
        await axios.post('/auth/webauthn/reset-pin', {
            email: page.props.auth.user.email,
            encrypted_master_key: newWrappedMasterKey,
            pin_salt: newSaltBase64,
        });

        masterKeySuccess.value = 'PIN alterado com sucesso!';
        masterKeyInput.value = '';
        masterKeyNewPin.value = '';
        masterKeyNewPinConfirm.value = '';
        showMasterKeyReset.value = false;
        masterKeyProcessing.value = false;

    } catch (err: any) {
        console.error('Master key PIN reset error:', err);
        masterKeyError.value = 'Master Key inválida';
        masterKeyProcessing.value = false;
    }
};
</script>

<template>
    <Head title="PIN Settings" />

    <SettingsLayout>
        <div class="space-y-6">
            <HeadingSmall
                title="PIN de Desbloqueio"
                description="Gere o PIN usado para desbloquear o teu cofre"
            />

            <!-- Change PIN with Current PIN -->
            <div class="rounded-lg border p-6 space-y-4">
                <div class="flex items-center gap-2 text-lg font-medium">
                    <Key class="h-5 w-5" />
                    Alterar PIN
                </div>
                <p class="text-sm text-muted-foreground">
                    Usa o teu PIN atual para definir um novo PIN.
                </p>

                <form @submit.prevent="changePinWithCurrentPin" class="space-y-4 max-w-md">
                    <div class="space-y-2">
                        <Label for="currentPin">PIN Atual</Label>
                        <Input
                            id="currentPin"
                            v-model="currentPin"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                            autocomplete="off"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="newPin">Novo PIN (mínimo 4 caracteres)</Label>
                        <Input
                            id="newPin"
                            v-model="newPin"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                            autocomplete="off"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="newPinConfirm">Confirmar Novo PIN</Label>
                        <Input
                            id="newPinConfirm"
                            v-model="newPinConfirm"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                            autocomplete="off"
                        />
                    </div>

                    <InputError v-if="error" :message="error" />
                    <p v-if="success" class="text-sm text-green-600">{{ success }}</p>

                    <Button type="submit" :disabled="processing">
                        <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin mr-2" />
                        Alterar PIN
                    </Button>
                </form>
            </div>

            <!-- Divider -->
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="bg-background px-2 text-muted-foreground">ou</span>
                </div>
            </div>

            <!-- Reset PIN with Master Key -->
            <div class="rounded-lg border p-6 space-y-4">
                <div class="flex items-center gap-2 text-lg font-medium">
                    <Shield class="h-5 w-5" />
                    Recuperar PIN com Master Key
                </div>
                <p class="text-sm text-muted-foreground">
                    Se esqueceste o PIN, podes usar a tua Master Key de backup para definir um novo.
                </p>

                <Button
                    v-if="!showMasterKeyReset"
                    variant="outline"
                    @click="showMasterKeyReset = true"
                >
                    <AlertTriangle class="h-4 w-4 mr-2" />
                    Usar Master Key
                </Button>

                <form
                    v-if="showMasterKeyReset"
                    @submit.prevent="changePinWithMasterKey"
                    class="space-y-4 max-w-md"
                >
                    <div class="space-y-2">
                        <Label for="masterKey">Master Key de Backup</Label>
                        <textarea
                            id="masterKey"
                            v-model="masterKeyInput"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono"
                            rows="2"
                            placeholder="Cola a tua Master Key aqui"
                        ></textarea>
                    </div>
                    <div class="space-y-2">
                        <Label for="masterKeyNewPin">Novo PIN (mínimo 4 caracteres)</Label>
                        <Input
                            id="masterKeyNewPin"
                            v-model="masterKeyNewPin"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                            autocomplete="off"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="masterKeyNewPinConfirm">Confirmar Novo PIN</Label>
                        <Input
                            id="masterKeyNewPinConfirm"
                            v-model="masterKeyNewPinConfirm"
                            type="password"
                            placeholder="••••"
                            maxlength="20"
                            autocomplete="off"
                        />
                    </div>

                    <InputError v-if="masterKeyError" :message="masterKeyError" />
                    <p v-if="masterKeySuccess" class="text-sm text-green-600">{{ masterKeySuccess }}</p>

                    <div class="flex gap-2">
                        <Button type="submit" :disabled="masterKeyProcessing">
                            <LoaderCircle v-if="masterKeyProcessing" class="h-4 w-4 animate-spin mr-2" />
                            Redefinir PIN
                        </Button>
                        <Button type="button" variant="outline" @click="showMasterKeyReset = false">
                            Cancelar
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </SettingsLayout>
</template>
