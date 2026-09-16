package com.delicat.studio.ui.editor

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.delicat.studio.engine.EditorEngine

/**
 * Keeps the editor alive across anything that rebuilds the UI.
 *
 * Deliberately empty of logic. Everything the editor does lives in
 * [EditorEngine], which is a plain class with no Android lifecycle in it and
 * can therefore be reasoned about — and compiled — on its own.
 */
class EditorViewModel(application: Application) : AndroidViewModel(application) {

    val editor = EditorEngine(application, viewModelScope)

    override fun onCleared() {
        editor.release()
        super.onCleared()
    }
}
